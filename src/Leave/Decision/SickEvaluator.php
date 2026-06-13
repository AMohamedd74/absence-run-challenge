<?php

declare(strict_types=1);

namespace App\Leave\Decision;

use App\Entity\LeaveRequest;
use App\Enum\LeaveType;
use App\Leave\Policy\OverlapChecker;
use App\Repository\LeaveRequestRepository;

/**
 * SICK never consumes the vacation balance (§8). With a certificate it is accepted
 * and credited back for any overlap with an approved vacation (§9); without one it
 * must be short — and "short" is measured over the *continuous* incapacity, not a
 * single request, so back-to-back 3-day notes can't slip past the certificate rule
 * (EntgFG §5: a certificate is required once incapacity exceeds 3 calendar days).
 */
final class SickEvaluator implements LeaveTypeEvaluator
{
    private const int CERTIFICATE_THRESHOLD_DAYS = 3;

    public function __construct(
        private readonly OverlapChecker $overlaps,
        private readonly LeaveRequestRepository $requests,
    ) {
    }

    public function supports(LeaveType $type): bool
    {
        return LeaveType::SICK === $type;
    }

    public function evaluate(LeaveRequest $request, \DateTimeImmutable $runDate): Evaluation
    {
        if ($request->hasMedicalCertificate()) {
            $overlap = $this->overlaps->approvedVacationOverlapDays($request);

            return $overlap > 0.0
                ? Evaluation::approved(-$overlap, 'sick during approved vacation — credited back (§9)')
                : Evaluation::approved(0.0, 'sick leave recorded');
        }

        if ($this->continuousIncapacityDays($request) <= self::CERTIFICATE_THRESHOLD_DAYS) {
            return Evaluation::approved(0.0, 'sick leave recorded (no certificate, ≤3 days)');
        }

        return Evaluation::rejected('medical certificate required beyond 3 continuous days');
    }

    /**
     * Calendar length of the continuous incapacity this request belongs to — its own
     * range grown by every other claimed sick period that overlaps or is directly
     * adjacent (no calendar-day gap), transitively. A gap (a returned-to-work day,
     * which here includes a weekend) starts a new period — see SPEC open questions.
     */
    private function continuousIncapacityDays(LeaveRequest $request): int
    {
        $others = array_filter(
            $this->requests->findClaimedSick($request->getEmployee()),
            static fn (LeaveRequest $r): bool => $r->getId() !== $request->getId(),
        );

        $start = $request->getStartDate();
        $end = $request->getEndDate();

        do {
            $grew = false;
            foreach ($others as $i => $other) {
                if ($this->touchesOrOverlaps($other, $start, $end)) {
                    $start = min($start, $other->getStartDate());
                    $end = max($end, $other->getEndDate());
                    unset($others[$i]);
                    $grew = true;
                }
            }
        } while ($grew);

        return (int) $start->diff($end)->days + 1;
    }

    /** Whether the request overlaps or is calendar-adjacent to the [start, end] span. */
    private function touchesOrOverlaps(LeaveRequest $other, \DateTimeImmutable $start, \DateTimeImmutable $end): bool
    {
        return $other->getStartDate() <= $end->modify('+1 day')
            && $other->getEndDate() >= $start->modify('-1 day');
    }
}
