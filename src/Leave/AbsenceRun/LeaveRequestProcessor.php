<?php

declare(strict_types=1);

namespace App\Leave\AbsenceRun;

use App\Entity\Decision;
use App\Entity\LeaveRequest;
use App\Enum\LeaveType;
use App\Leave\Decision\Evaluation;
use App\Leave\Decision\EvaluatorRegistry;
use App\Leave\Decision\LeaveRequestValidator;
use App\Repository\DecisionRepository;
use App\Repository\LeaveRequestRepository;
use Psr\Log\LoggerInterface;

/**
 * Orchestrates the absence run. It owns the *flow* only — what runs in what order
 * and how failures are isolated — and delegates the work:
 *   - {@see LeaveRequestValidator}  precondition checks
 *   - {@see EvaluatorRegistry}      per-type decision (Strategy)
 *   - {@see DecisionRecorder}       idempotent HR post + balance update
 *
 * Order matters (see SPEC.md): cancelled approvals are reversed first (§12), then
 * vacations/unpaid/special are decided, then sick — so the §9 "overlaps an
 * already-approved vacation" check holds regardless of submission order.
 *
 * Note: a decided request is treated as terminal. The run only ever decides PENDING
 * requests and reverses CANCELLED ones; re-opening a decided request is out of scope
 * and {@see DecisionRecorder::record()} fails loudly if it sees one.
 */
final class LeaveRequestProcessor
{
    public function __construct(
        private readonly LeaveRequestRepository $leaveRequests,
        private readonly DecisionRepository $decisions,
        private readonly LeaveRequestValidator $validator,
        private readonly EvaluatorRegistry $evaluators,
        private readonly DecisionRecorder $recorder,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function processPending(\DateTimeImmutable $runDate): RunReport
    {
        $decisions = [];
        $skipped = 0;

        // §12 — reverse approvals whose request has since been cancelled.
        foreach ($this->decisions->findApprovedAwaitingReversal() as $approval) {
            try {
                foreach ($this->reverse($approval, $runDate) as $row) {
                    $decisions[] = $row;
                }
            } catch (\Throwable $e) {
                $this->skip($e);
                ++$skipped;
            }
        }

        // Decide pending requests, vacations/unpaid/special before sick so the §9
        // "overlaps an already-approved vacation" check sees this run's approvals.
        [$sick, $other] = $this->partitionPending();
        foreach ([...$other, ...$sick] as $request) {
            try {
                $decisions[] = $this->decide($request, $runDate);
            } catch (\Throwable $e) {
                $this->skip($e);
                ++$skipped;
            }
        }

        return new RunReport($decisions, $skipped);
    }

    /**
     * Reverse one cancelled approval, cascading to any §9 sick credit it underpinned:
     * cancelling a vacation revokes the credit a sick-during-it request received,
     * otherwise that credit would dangle and leave the balance below zero. Throws on
     * failure so the caller can count the skip.
     *
     * @return list<array{request: int, status: string, days: float, reason: string}>
     */
    private function reverse(Decision $approval, \DateTimeImmutable $runDate): array
    {
        $rows = [];
        $request = $approval->getRequest();

        $row = $this->recorder->recordReversal(
            $request,
            -$approval->getBalanceDelta(),
            'cancelled after approval — credited back (§12)',
            $runDate,
        );
        if (null !== $row) {
            $rows[] = $row;
        }

        if (LeaveType::VACATION === $request->getType()) {
            foreach ($this->decisions->findActiveSickCredits($request->getEmployee()) as $credit) {
                if (!$this->rangesOverlap($credit->getRequest(), $request)) {
                    continue;
                }
                $row = $this->recorder->recordReversal(
                    $credit->getRequest(),
                    -$credit->getBalanceDelta(),
                    '§9 credit revoked — underlying vacation cancelled',
                    $runDate,
                );
                if (null !== $row) {
                    $rows[] = $row;
                }
            }
        }

        return $rows;
    }

    /**
     * @return array{request: int, status: string, days: float, reason: string}
     */
    private function decide(LeaveRequest $request, \DateTimeImmutable $runDate): array
    {
        $reason = $this->validator->validate($request);
        $evaluation = null !== $reason
            ? Evaluation::rejected($reason)
            : $this->evaluators->for($request->getType())->evaluate($request, $runDate);

        return $this->recorder->record($request, $evaluation, $runDate);
    }

    /**
     * @return array{0: list<LeaveRequest>, 1: list<LeaveRequest>} [sick, other]
     */
    private function partitionPending(): array
    {
        $sick = [];
        $other = [];
        foreach ($this->leaveRequests->findPending() as $request) {
            if (LeaveType::SICK === $request->getType()) {
                $sick[] = $request;
            } else {
                $other[] = $request;
            }
        }

        return [$sick, $other];
    }

    private function rangesOverlap(LeaveRequest $a, LeaveRequest $b): bool
    {
        return $a->getStartDate() <= $b->getEndDate() && $b->getStartDate() <= $a->getEndDate();
    }

    private function skip(\Throwable $e): void
    {
        $this->logger->warning('absence-run: skipped a request', ['error' => $e->getMessage()]);
    }
}
