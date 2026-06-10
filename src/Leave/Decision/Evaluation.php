<?php

declare(strict_types=1);

namespace App\Leave\Decision;

use App\Enum\LeaveStatus;

/**
 * The transient outcome of evaluating a leave request: the decision, its effect on
 * the vacation balance, and why. {@see DecisionRecorder} turns this into a persisted
 * {@see \App\Entity\Decision} and posts it to HR.
 */
final readonly class Evaluation
{
    private function __construct(
        public LeaveStatus $status,
        /** Days to add to the vacation balance: + consumes, − credits/reverses, 0 = no impact. */
        public float $balanceDelta,
        public string $reason,
    ) {
    }

    public static function approved(float $balanceDelta, string $reason): self
    {
        return new self(LeaveStatus::APPROVED, $balanceDelta, $reason);
    }

    public static function rejected(string $reason): self
    {
        return new self(LeaveStatus::REJECTED, 0.0, $reason);
    }

    /** A §12 reversal of an earlier approval (negative delta credits the days back). */
    public static function reversed(float $balanceDelta, string $reason): self
    {
        return new self(LeaveStatus::CANCELLED, $balanceDelta, $reason);
    }
}
