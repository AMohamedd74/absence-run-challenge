<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\AbsenceRunCommand;
use App\Entity\Employee;
use App\Entity\LeaveBalance;
use App\Entity\LeaveRequest;
use App\Enum\LeaveStatus;
use App\Enum\LeaveType;
use App\Tests\AbsenceRunTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;

final class AbsenceRunCommandTest extends AbsenceRunTestCase
{
    private LockFactory $lockFactory;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->lockFactory = new LockFactory(new FlockStore());
    }

    public function testRefusesToRunWhileAnotherRunHoldsTheLock(): void
    {
        $employee = new Employee('Locked Out', new \DateTimeImmutable('2018-01-01'), 5, 'BE', 28);
        $balance = new LeaveBalance($employee, 2025, 0.0, null, 0.0);
        $request = new LeaveRequest($employee, LeaveType::VACATION,
            new \DateTimeImmutable('2025-05-19'), new \DateTimeImmutable('2025-05-23'),
            new \DateTimeImmutable('2025-04-10'));
        $this->persist($employee, $balance, $request);

        // Another process holds the run lock.
        $other = $this->lockFactory->createLock('absence-run');
        self::assertTrue($other->acquire());

        try {
            $tester = new CommandTester(new AbsenceRunCommand($this->processor(), $this->lockFactory));
            $exitCode = $tester->execute(['--date' => '2025-04-15']);

            self::assertSame(AbsenceRunCommand::EXIT_LOCKED, $exitCode);
            self::assertStringContainsString('already in progress', $tester->getDisplay());
            self::assertSame(LeaveStatus::PENDING, $request->getStatus(), 'nothing was processed');
            self::assertSame([], $this->hrApi->calls, 'nothing was posted to HR');
        } finally {
            $other->release();
        }
    }

    public function testReleasesTheLockAfterARun(): void
    {
        $tester = new CommandTester(new AbsenceRunCommand($this->processor(), $this->lockFactory));

        self::assertSame(Command::SUCCESS, $tester->execute(['--date' => '2025-04-15']));

        // A second run must be able to take the lock again.
        self::assertSame(Command::SUCCESS, $tester->execute(['--date' => '2025-04-15']));
    }
}
