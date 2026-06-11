<?php

declare(strict_types=1);

namespace App\Tests\Leave\AbsenceRun;

use App\DataFixtures\ScenarioFixtures;
use App\Entity\Employee;
use App\Entity\LeaveBalance;
use App\Entity\LeaveRequest;
use App\Enum\LeaveStatus;
use App\Repository\EmployeeRepository;
use App\Repository\LeaveBalanceRepository;
use App\Tests\AbsenceRunTestCase;

/**
 * Asserts the edge-case catalogue in {@see ScenarioFixtures}, so the demo data
 * stays honest (it cannot claim a scenario it no longer produces).
 */
final class ScenarioFixturesTest extends AbsenceRunTestCase
{
    private const RUN_DATE = '2025-04-15';

    public function testEachScenarioProducesItsExpectedOutcome(): void
    {
        (new ScenarioFixtures())->load($this->em);

        $this->processor()->processPending(new \DateTimeImmutable(self::RUN_DATE));

        // Sick leave matrix.
        self::assertSame(LeaveStatus::APPROVED, $this->statusOf('Sick NoCert Short', '2025-05-19'), 'no cert, ≤3 days');
        self::assertSame(0.0, $this->usedDays('Sick NoCert Short'));

        self::assertSame(LeaveStatus::REJECTED, $this->statusOf('Sick NoCert Long', '2025-05-19'), 'no cert, >3 days');
        self::assertSame(0.0, $this->usedDays('Sick NoCert Long'));

        self::assertSame(LeaveStatus::APPROVED, $this->statusOf('Sick Cert NoOverlap', '2025-05-19'));
        self::assertSame(0.0, $this->usedDays('Sick Cert NoOverlap'));

        self::assertSame(LeaveStatus::APPROVED, $this->statusOf('Sick Cert Overlap', '2025-03-18'));
        self::assertSame(3.0, $this->usedDays('Sick Cert Overlap'), '2 overlapping working days credited (5 → 3)');

        self::assertSame(LeaveStatus::APPROVED, $this->statusOf('Sick Cert HalfDay Overlap', '2025-03-17'));
        self::assertSame(3.0, $this->usedDays('Sick Cert HalfDay Overlap'), 'half-day-aware credit of 1.5 (4.5 → 3.0)');

        // Other absence types.
        self::assertSame(LeaveStatus::APPROVED, $this->statusOf('Unpaid', '2025-05-05'));
        self::assertSame(0.0, $this->usedDays('Unpaid'));

        self::assertSame(LeaveStatus::APPROVED, $this->statusOf('Special', '2025-06-02'));
        self::assertSame(0.0, $this->usedDays('Special'));

        // Balance & overlap.
        self::assertSame(LeaveStatus::REJECTED, $this->statusOf('Insufficient', '2025-05-19'));
        self::assertSame(26.0, $this->usedDays('Insufficient'));

        self::assertSame(LeaveStatus::APPROVED, $this->statusOf('Overlap', '2025-05-19'), 'first-submitted wins');
        self::assertSame(LeaveStatus::REJECTED, $this->statusOf('Overlap', '2025-05-21'), 'later overlap rejected');
        self::assertSame(5.0, $this->usedDays('Overlap'));

        self::assertSame(LeaveStatus::APPROVED, $this->statusOf('Weekend Overlap', '2025-07-04'));
        self::assertSame(LeaveStatus::APPROVED, $this->statusOf('Weekend Overlap', '2025-07-06'), 'shared weekend is no clash');
        self::assertSame(3.0, $this->usedDays('Weekend Overlap'));

        // Leaver whose pro-rata entitlement (12) is below days already used (20):
        // remaining is −8, so the request is rejected and the balance stays over-drawn.
        self::assertSame(LeaveStatus::REJECTED, $this->statusOf('Leaver Overdrawn', '2025-06-16'), 'over-drawn leaver');
        self::assertSame(20.0, $this->usedDays('Leaver Overdrawn'), 'over-drawn balance left as-is, not clawed back');

        // Bad data.
        self::assertSame(LeaveStatus::REJECTED, $this->statusOf('Bad WorkingDays', '2025-05-19'), 'invalid workingDaysPerWeek');
        self::assertSame(0.0, $this->usedDays('Bad WorkingDays'));
    }

    private function usedDays(string $employeeName): float
    {
        $balances = $this->em->getRepository(LeaveBalance::class);
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
