<?php

declare(strict_types=1);

namespace App\Leave\Decision;

use App\Entity\LeaveRequest;
use App\Enum\LeaveType;
use App\Leave\Policy\BalanceCalculator;
use App\Leave\Policy\OverlapChecker;
use App\Leave\Policy\WorkingDayCounter;

/**
 * VACATION consumes the balance: rejected if it overlaps an approved period (§10)
 * or exceeds the remaining balance (§11), otherwise approved for the working days
 * it consumes (§4).
 */
final class VacationEvaluator implements LeaveTypeEvaluator
{
    public function __construct(
        private readonly OverlapChecker $overlaps,
        private readonly BalanceCalculator $balances,
        private readonly WorkingDayCounter $workingDays,
    ) {
    }

    public function supports(LeaveType $type): bool
    {
        return LeaveType::VACATION === $type;
    }

    public function evaluate(LeaveRequest $request, \DateTimeImmutable $runDate): Evaluation
    {
        if ($this->overlaps->overlapsApprovedVacation($request)) {
            return Evaluation::rejected('overlaps an already-approved leave period');
        }

        $consumed = $this->workingDays->forRequest($request);
        if ($consumed > $this->balances->remainingFor($request, $runDate)) {
            return Evaluation::rejected('insufficient balance');
        }

        return Evaluation::approved($consumed, 'within balance');
    }
}
