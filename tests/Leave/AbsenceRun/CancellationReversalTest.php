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
 * §12: a request approved in one run and cancelled before the next must have its
 * days credited back — exactly once.
 */
final class CancellationReversalTest extends AbsenceRunTestCase
{
    public function testApprovedThenCancelledIsCreditedBackOnce(): void
    {
        $employee = new Employee('Mover', new \DateTimeImmutable('2018-01-01'), 5, 'BE', 28);
        $balance = new LeaveBalance($employee, 2025, 0.0, null, 0.0);
        $request = new LeaveRequest($employee, LeaveType::VACATION,
            new \DateTimeImmutable('2025-05-19'), new \DateTimeImmutable('2025-05-23'),
            new \DateTimeImmutable('2025-04-10'));
        $this->persist($employee, $balance, $request);

        // Run 1: approve and deduct.
        $this->processor()->processPending(new \DateTimeImmutable('2025-04-15'));
        self::assertSame(LeaveStatus::APPROVED, $request->getStatus());
        self::assertSame(5.0, $balance->getUsedDays());
        $callsAfterApproval = \count($this->hrApi->calls);

        // The request is cancelled outside this batch.
        $request->setStatus(LeaveStatus::CANCELLED);
        $this->em->flush();

        // Run 2: reconciliation credits the days back and posts a cancellation.
        $this->processor()->processPending(new \DateTimeImmutable('2025-04-16'));
        self::assertSame(0.0, $balance->getUsedDays(), '5 days credited back');

        $reversal = $this->hrApi->calls[$callsAfterApproval]['decision'];
        self::assertSame('cancelled', $reversal['decision']);
        self::assertSame(-5.0, $reversal['days']);
        $callsAfterReversal = \count($this->hrApi->calls);

        // Run 3: nothing left to reverse — no double credit, no extra HR post.
        $this->processor()->processPending(new \DateTimeImmutable('2025-04-17'));
        self::assertSame(0.0, $balance->getUsedDays());
        self::assertCount($callsAfterReversal, $this->hrApi->calls);
    }

    public function testCancellingAVacationAlsoRevokesItsSickCredit(): void
    {
        $employee = new Employee('Dilan', new \DateTimeImmutable('2015-01-01'), 5, 'BY', 30);
        $balance = new LeaveBalance($employee, 2025, 0.0, null, 0.0);
        $vacation = new LeaveRequest($employee, LeaveType::VACATION,
            new \DateTimeImmutable('2025-05-19'), new \DateTimeImmutable('2025-05-23'),
            new \DateTimeImmutable('2025-04-10'));
        $sick = (new LeaveRequest($employee, LeaveType::SICK,
            new \DateTimeImmutable('2025-05-20'), new \DateTimeImmutable('2025-05-21'),
            new \DateTimeImmutable('2025-04-11')))->setMedicalCertificate(true);
        $this->persist($employee, $balance, $vacation, $sick);

        // Run 1: vacation approved (+5), sick credited −2 (§9) → 3 used.
        $this->processor()->processPending(new \DateTimeImmutable('2025-04-15'));
        self::assertSame(3.0, $balance->getUsedDays());

        // The vacation is cancelled externally.
        $vacation->setStatus(LeaveStatus::CANCELLED);
        $this->em->flush();

        // Run 2: reversing the vacation must also revoke the now-meaningless sick
        // credit, returning the balance to 0 — not leaving it at −2.
        $this->processor()->processPending(new \DateTimeImmutable('2025-04-16'));
        self::assertSame(0.0, $balance->getUsedDays(), 'dependent §9 credit revoked, no phantom days');

        // Run 3: idempotent — nothing further reverses.
        $callsBefore = \count($this->hrApi->calls);
        $this->processor()->processPending(new \DateTimeImmutable('2025-04-17'));
        self::assertSame(0.0, $balance->getUsedDays());
        self::assertCount($callsBefore, $this->hrApi->calls);
    }

    public function testCancelledZeroImpactRequestStillPostsCancellationToHr(): void
    {
        $employee = new Employee('Eva', new \DateTimeImmutable('2019-01-01'), 5, 'BE', 28);
        $balance = new LeaveBalance($employee, 2025, 0.0, null, 0.0);
        $unpaid = new LeaveRequest($employee, LeaveType::UNPAID,
            new \DateTimeImmutable('2025-05-05'), new \DateTimeImmutable('2025-05-09'),
            new \DateTimeImmutable('2025-04-02'));
        $this->persist($employee, $balance, $unpaid);

        $this->processor()->processPending(new \DateTimeImmutable('2025-04-15'));
        $callsAfterApproval = \count($this->hrApi->calls);

        $unpaid->setStatus(LeaveStatus::CANCELLED);
        $this->em->flush();

        $this->processor()->processPending(new \DateTimeImmutable('2025-04-16'));

        $reversal = $this->hrApi->calls[$callsAfterApproval]['decision'];
        self::assertSame('cancelled', $reversal['decision'], 'HR is told even though no days change');
        self::assertSame(0.0, $reversal['days']);
        self::assertSame(0.0, $balance->getUsedDays());
    }

    public function testReopeningADecidedRequestIsRejectedNotSilentlyDoubleApplied(): void
    {
        $employee = new Employee('Reopen', new \DateTimeImmutable('2018-01-01'), 5, 'BE', 28);
        $balance = new LeaveBalance($employee, 2025, 0.0, null, 0.0);
        $request = new LeaveRequest($employee, LeaveType::VACATION,
            new \DateTimeImmutable('2025-05-19'), new \DateTimeImmutable('2025-05-23'),
            new \DateTimeImmutable('2025-04-10'));
        $this->persist($employee, $balance, $request);

        $this->processor()->processPending(new \DateTimeImmutable('2025-04-15'));
        self::assertSame(5.0, $balance->getUsedDays());

        // Force an unsupported re-open back to PENDING.
        $request->setStatus(LeaveStatus::PENDING);
        $this->em->flush();

        // The re-decision collides with the existing '{id}:approved' key; it must be
        // skipped (logged), NOT silently re-applied to double the balance.
        $this->processor()->processPending(new \DateTimeImmutable('2025-04-16'));
        self::assertSame(5.0, $balance->getUsedDays(), 'balance not doubled');
    }
}
