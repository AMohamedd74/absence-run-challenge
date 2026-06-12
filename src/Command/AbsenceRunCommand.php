<?php

declare(strict_types=1);

namespace App\Command;

use App\Leave\AbsenceRun\LeaveRequestProcessor;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Lock\LockFactory;

#[AsCommand(
    name: 'app:absence:run',
    description: 'Process pending leave requests and post decisions to the HR system.',
)]
final class AbsenceRunCommand extends Command
{
    /**
     * Exit code when another run already holds the lock — distinct from FAILURE (1,
     * "ran but skipped requests") so monitoring can tell the two apart.
     */
    public const int EXIT_LOCKED = 2;

    private const string LOCK_NAME = 'absence-run';

    public function __construct(
        private readonly LeaveRequestProcessor $processor,
        private readonly LockFactory $lockFactory,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this->addOption(
            'date',
            null,
            InputOption::VALUE_REQUIRED,
            'Run date in Y-m-d format. Defaults to today.',
        );
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $dateOption = $input->getOption('date');
        $runDate = \is_string($dateOption) && '' !== $dateOption
            ? new \DateTimeImmutable($dateOption)
            : new \DateTimeImmutable('today');

        // Only one run at a time: overlapping runs (cron overlap, a manual run next
        // to the scheduled one) would interleave balance updates. Fail fast rather
        // than wait — the next scheduled run picks up whatever this one would have.
        // The default flock store releases the lock if the process dies.
        $lock = $this->lockFactory->createLock(self::LOCK_NAME);
        if (!$lock->acquire()) {
            $io->error('Another absence run is already in progress — aborting.');

            return self::EXIT_LOCKED;
        }

        try {
            $io->title(sprintf('Absence run — %s', $runDate->format('Y-m-d')));

            $report = $this->processor->processPending($runDate);

            if ($report->isEmpty()) {
                $io->success('No pending requests to process.');

                return Command::SUCCESS;
            }

            if ([] !== $report->decisions) {
                $io->table(
                    ['Request', 'Decision', 'Days'],
                    array_map(
                        static fn (array $row): array => [$row['request'], $row['status'], $row['days']],
                        $report->decisions,
                    ),
                );
            }

            // A skipped request couldn't be processed (bad data, HR post failed, …); it
            // stays pending and is retried next run. Surface it as a non-zero exit so the
            // scheduler/monitoring notices, rather than reporting silent success.
            if ($report->hasSkips()) {
                $io->error(sprintf(
                    'Processed %d request(s); %d skipped — see logs.',
                    $report->decisionCount(),
                    $report->skipped,
                ));

                return Command::FAILURE;
            }

            $io->success(sprintf('Processed %d request(s).', $report->decisionCount()));

            return Command::SUCCESS;
        } finally {
            $lock->release();
        }
    }
}
