<?php

declare(strict_types=1);

namespace App\Leave\Decision;

use App\Enum\LeaveType;

/**
 * Dispatches a request to the evaluator for its leave type. Adding a type means
 * adding a {@see LeaveTypeEvaluator} and registering it here — the rest of the
 * pipeline is untouched.
 */
final class EvaluatorRegistry
{
    /** @var list<LeaveTypeEvaluator> */
    private readonly array $evaluators;

    public function __construct(
        VacationEvaluator $vacation,
        SickEvaluator $sick,
        UnpaidEvaluator $unpaid,
        SpecialEvaluator $special,
    ) {
        $this->evaluators = [$vacation, $sick, $unpaid, $special];
    }

    public function for(LeaveType $type): LeaveTypeEvaluator
    {
        foreach ($this->evaluators as $evaluator) {
            if ($evaluator->supports($type)) {
                return $evaluator;
            }
        }

        throw new \LogicException(sprintf('No evaluator registered for leave type "%s".', $type->value));
    }
}
