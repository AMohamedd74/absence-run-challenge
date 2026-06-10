<?php

declare(strict_types=1);

namespace App\Tests\Leave\AbsenceRun;

use App\DataFixtures\AppFixtures;
use App\Entity\Employee;
use App\Entity\LeaveRequest;
use App\Enum\LeaveStatus;
use App\Repository\EmployeeRepository;
use App\Repository\LeaveBalanceRepository;
use App\Tests\AbsenceRunTestCase;

/**
 * The seeded period as a golden oracle (SPEC.md). Loads the real fixtures and
 * asserts every decision and final balance for the 2025-04-15 run.
 */
final class AbsenceRunOracleTest extends AbsenceRunTestCase
{
    private const RUN_DATE = '2025-04-15';

    public function testProcessesTheSeededPeriod(): void
    {
        (new AppFixtures())->load($this->em);

        $summary = $this->processor()->processPending(new \DateTimeImmutable(self::RUN_DATE));

        // Final balances per employee.
        self::assertSame(26.5, $this->usedDays('Anna Becker'), 'carryover lapsed; only 04-28→30 approved (+2.5)');
        self::assertSame(14.0, $this->usedDays('Bjarne Vogt'), 'part-time entitlement 17, used 14 → 5 rejected');
        self::assertSame(21.0, $this->usedDays('Carla Roth'), 'joiner entitlement 25, used 21 → 5 rejected');
        self::assertSame(7.0, $this->usedDays('Dilan Yilmaz'), '3 sick days credited back (10 → 7)');
        self::assertSame(8.5, $this->usedDays('Eva Klein'), 'unpaid no impact; 06-05→11 approved (+3.5)');
        self::assertSame(4.0, $this->usedDays('Felix Wolf'), 'first vacation approved (+4), overlap rejected');

        // Key per-request decisions.
        self::assertSame(LeaveStatus::REJECTED, $this->statusOf('Anna Becker', '2025-05-19'), 'insufficient after lapse');
        self::assertSame(LeaveStatus::APPROVED, $this->statusOf('Anna Becker', '2025-04-28'));
        self::assertSame(LeaveStatus::APPROVED, $this->statusOf('Anna Becker', '2025-06-02'), 'special always approved');
        self::assertSame(LeaveStatus::REJECTED, $this->statusOf('Bjarne Vogt', '2025-07-07'), 'part-timer, insufficient');
        self::assertSame(LeaveStatus::REJECTED, $this->statusOf('Carla Roth', '2025-07-07'), 'joiner, insufficient');
        self::assertSame(LeaveStatus::APPROVED, $this->statusOf('Felix Wolf', '2025-05-26'));
        self::assertSame(LeaveStatus::REJECTED, $this->statusOf('Felix Wolf', '2025-05-28'), 'overlaps the approved one');
        self::assertSame(LeaveStatus::APPROVED, $this->statusOf('Dilan Yilmaz', '2025-03-24'), 'sick credited');

        // 10 decisions posted to HR: 6 approved, 4 rejected.
        self::assertCount(10, $this->hrApi->calls);
        $decisions = array_map(static fn (array $c): string => $c['decision']['decision'], $this->hrApi->calls);
        self::assertSame(6, \count(array_filter($decisions, static fn (string $d): bool => 'approved' === $d)));
        self::assertSame(4, \count(array_filter($decisions, static fn (string $d): bool => 'rejected' === $d)));
        self::assertCount(10, $summary);
    }

    public function testReRunPostsNothingNewAndLeavesBalancesUnchanged(): void
    {
        (new AppFixtures())->load($this->em);

        $this->processor()->processPending(new \DateTimeImmutable(self::RUN_DATE));
        $callsAfterFirstRun = \count($this->hrApi->calls);
        $balancesAfterFirstRun = [
            'Anna Becker' => $this->usedDays('Anna Becker'),
            'Dilan Yilmaz' => $this->usedDays('Dilan Yilmaz'),
            'Felix Wolf' => $this->usedDays('Felix Wolf'),
        ];

        $secondSummary = $this->processor()->processPending(new \DateTimeImmutable(self::RUN_DATE));

        self::assertCount($callsAfterFirstRun, $this->hrApi->calls, 'no duplicate HR posts on re-run');
        self::assertSame([], $secondSummary, 'nothing left pending');
        foreach ($balancesAfterFirstRun as $name => $used) {
            self::assertSame($used, $this->usedDays($name), "balance unchanged for $name");
        }
    }

    private function usedDays(string $employeeName): float
    {
        $balances = $this->em->getRepository(\App\Entity\LeaveBalance::class);
        \assert($balances instanceof LeaveBalanceRepository);

        return $balances->findForEmployeeAndYear($this->employee($employeeName), 2025)->getUsedDays();
    }

    private function statusOf(string $employeeName, string $startDate): LeaveStatus
    {
        $request = $this->em->getRepository(LeaveRequest::class)->findOneBy([
            'employee' => $this->employee($employeeName),
            'startDate' => new \DateTimeImmutable($startDate),
        ]);
        \assert($request instanceof LeaveRequest);

        return $request->getStatus();
    }

    private function employee(string $name): Employee
    {
        $employees = $this->em->getRepository(Employee::class);
        \assert($employees instanceof EmployeeRepository);
        $employee = $employees->findOneBy(['name' => $name]);
        \assert($employee instanceof Employee);

        return $employee;
    }
}
