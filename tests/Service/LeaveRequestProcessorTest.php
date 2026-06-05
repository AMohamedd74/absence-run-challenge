<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Employee;
use App\Entity\LeaveBalance;
use App\Entity\LeaveRequest;
use App\Enum\LeaveStatus;
use App\Enum\LeaveType;
use App\Tests\AbsenceRunTestCase;

/**
 * Happy-path coverage for the (naive) processor.
 *
 * These three tests describe the simplest correct behaviour and pass against the
 * starter code. They are intentionally thin — extending this suite to pin down
 * the real rules (weekends, holidays, carryover, pro-rata, sick leave, …) is the
 * heart of the exercise.
 */
final class LeaveRequestProcessorTest extends AbsenceRunTestCase
{
    public function testApprovesVacationWithinBalance(): void
    {
        $employee = new Employee('Full Timer', new \DateTimeImmutable('2018-01-01'), 5, 'BE', 28);
        $balance = new LeaveBalance($employee, 2025, 0.0, null, 0.0);
        $request = $this->vacation($employee, '2025-05-19', '2025-05-23');
        $this->persist($employee, $balance, $request);

        $summary = $this->processor()->processPending(new \DateTimeImmutable('2025-04-15'));

        self::assertCount(1, $summary);
        self::assertSame(LeaveStatus::APPROVED, $request->getStatus());
        self::assertSame(5.0, $balance->getUsedDays());
    }

    public function testRejectsVacationExceedingBalance(): void
    {
        $employee = new Employee('Low Balance', new \DateTimeImmutable('2018-01-01'), 5, 'BE', 5);
        $balance = new LeaveBalance($employee, 2025, 0.0, null, 4.0);
        $request = $this->vacation($employee, '2025-05-19', '2025-05-23');
        $this->persist($employee, $balance, $request);

        $this->processor()->processPending(new \DateTimeImmutable('2025-04-15'));

        self::assertSame(LeaveStatus::REJECTED, $request->getStatus());
        self::assertSame(4.0, $balance->getUsedDays());
    }

    public function testReportsEachDecisionToHrApi(): void
    {
        $employee = new Employee('Full Timer', new \DateTimeImmutable('2018-01-01'), 5, 'BE', 28);
        $balance = new LeaveBalance($employee, 2025, 0.0, null, 0.0);
        $request = $this->vacation($employee, '2025-05-19', '2025-05-23');
        $this->persist($employee, $balance, $request);

        $this->processor()->processPending(new \DateTimeImmutable('2025-04-15'));

        self::assertCount(1, $this->hrApi->calls);
        self::assertSame('approved', $this->hrApi->calls[0]['decision']['decision']);
        self::assertSame(5.0, $this->hrApi->calls[0]['decision']['days']);
    }

    private function vacation(Employee $employee, string $start, string $end): LeaveRequest
    {
        return new LeaveRequest(
            $employee,
            LeaveType::VACATION,
            new \DateTimeImmutable($start),
            new \DateTimeImmutable($end),
            new \DateTimeImmutable('2025-04-10'),
        );
    }
}
