<?php

declare(strict_types=1);

namespace App\Leave\Decision;

use App\Entity\LeaveRequest;
use App\Enum\LeaveType;

/** SPECIAL is drawn from a separate allotment and always approved (§8). */
final class SpecialEvaluator implements LeaveTypeEvaluator
{
    public function supports(LeaveType $type): bool
    {
        return LeaveType::SPECIAL === $type;
    }

    public function evaluate(LeaveRequest $request, \DateTimeImmutable $runDate): Evaluation
    {
        return Evaluation::approved(0.0, 'special leave approved');
    }
}
