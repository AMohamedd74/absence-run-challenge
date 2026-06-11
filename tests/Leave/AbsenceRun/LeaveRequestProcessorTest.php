<?php

declare(strict_types=1);

namespace App\Tests\Leave\AbsenceRun;

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

        $report = $this->processor()->processPending(new \DateTimeImmutable('2025-04-15'));

        self::assertCount(1, $report->decisions);
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

    public function testVacationsSharingOnlyAWeekendDoNotConflict(): void
    {
        // Fri→Sun and Sun→Tue share only Sunday — no common working day, so §10 must
        // not reject the second (calendar ranges overlap, working days do not).
        $employee = new Employee('Weekender', new \DateTimeImmutable('2018-01-01'), 5, 'BY', 28);
        $balance = new LeaveBalance($employee, 2025, 0.0, null, 0.0);
        $first = $this->vacation($employee, '2025-07-04', '2025-07-06');  // Fri–Sun
        $second = $this->vacation($employee, '2025-07-06', '2025-07-08'); // Sun–Tue
        $this->persist($employee, $balance, $first, $second);

        $this->processor()->processPending(new \DateTimeImmutable('2025-04-15'));

        self::assertSame(LeaveStatus::APPROVED, $first->getStatus());
        self::assertSame(LeaveStatus::APPROVED, $second->getStatus(), 'shared weekend is not a working-day overlap');
    }

    public function testAFailedRequestIsSkippedAndCounted(): void
    {
        // No LeaveBalance row → the run can't compute remaining → the request is
        // logged and skipped (it stays pending, retried next run), and the report
        // counts the skip — which the command turns into a non-zero exit code.
        $employee = new Employee('No Balance', new \DateTimeImmutable('2018-01-01'), 5, 'BE', 28);
        $request = $this->vacation($employee, '2025-05-19', '2025-05-23');
        $this->persist($employee, $request); // deliberately no balance

        $report = $this->processor()->processPending(new \DateTimeImmutable('2025-04-15'));

        self::assertTrue($report->hasSkips());
        self::assertSame(1, $report->skipped);
        self::assertSame([], $report->decisions);
        self::assertSame(LeaveStatus::PENDING, $request->getStatus(), 'left pending for retry');
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
