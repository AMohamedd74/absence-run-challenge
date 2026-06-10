<?php

declare(strict_types=1);

namespace App\Leave\Decision;

use App\Entity\LeaveRequest;
use App\Enum\LeaveType;
use App\Leave\Policy\OverlapChecker;

/**
 * SICK never consumes the vacation balance (§8). With a certificate it is accepted
 * and credited back for any overlap with an approved vacation (§9); without one it
 * must be short — ≤ 3 calendar days (EntgFG §5), otherwise it is rejected.
 */
final class SickEvaluator implements LeaveTypeEvaluator
{
    public function __construct(private readonly OverlapChecker $overlaps)
    {
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

        $calendarDays = (int) $request->getStartDate()->diff($request->getEndDate())->days + 1;

        return $calendarDays <= 3
            ? Evaluation::approved(0.0, 'sick leave recorded (no certificate, ≤3 days)')
            : Evaluation::rejected('medical certificate required beyond 3 days');
    }
}
