<?php

declare(strict_types=1);

namespace App\Leave\Decision;

use App\Entity\LeaveRequest;
use App\Enum\LeaveType;

/** UNPAID is recorded with no effect on the vacation balance (§8). */
final class UnpaidEvaluator implements LeaveTypeEvaluator
{
    public function supports(LeaveType $type): bool
    {
        return LeaveType::UNPAID === $type;
    }

    public function evaluate(LeaveRequest $request, \DateTimeImmutable $runDate): Evaluation
    {
        return Evaluation::approved(0.0, 'unpaid leave recorded');
    }
}
