<?php

declare(strict_types=1);

namespace App\Leave\Decision;

use App\Entity\LeaveRequest;
use App\Enum\LeaveType;

/**
 * Decides a single request of one leave type (Strategy). One implementation per
 * {@see LeaveType}; the {@see EvaluatorRegistry} dispatches by type.
 */
interface LeaveTypeEvaluator
{
    public function supports(LeaveType $type): bool;

    public function evaluate(LeaveRequest $request, \DateTimeImmutable $runDate): Evaluation;
}
