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

#[AsCommand(
    name: 'app:absence:run',
    description: 'Process pending leave requests and post decisions to the HR system.',
)]
final class AbsenceRunCommand extends Command
{
    public function __construct(private readonly LeaveRequestProcessor $processor)
    {
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
    }
}
