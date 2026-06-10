<?php

declare(strict_types=1);

namespace App\Leave\AbsenceRun;

use App\Entity\LeaveRequest;
use App\Leave\Decision\Evaluation;

/**
 * Maps a domain decision (a request + its {@see Evaluation}) to the JSON body the
 * HR API expects. Keeping the wire format here means the HR client stays a generic
 * array transport and {@see DecisionRecorder} reads as guard → send → persist.
 */
final readonly class DecisionPayload
{
    public function __construct(
        private LeaveRequest $request,
        private Evaluation $evaluation,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'employeeId' => $this->request->getEmployee()->getId(),
            'requestId' => $this->request->getId(),
            'type' => $this->request->getType()->value,
            'decision' => $this->evaluation->status->value,
            'days' => $this->evaluation->balanceDelta,
            'reason' => $this->evaluation->reason,
        ];
    }
}
