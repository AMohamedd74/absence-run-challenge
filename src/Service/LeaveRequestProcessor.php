<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\LeaveBalance;
use App\Entity\LeaveRequest;
use App\Enum\LeaveStatus;
use App\Hr\HrApiClientInterface;
use App\Repository\LeaveBalanceRepository;
use App\Repository\LeaveRequestRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Processes pending leave requests and reports each decision to the HR system.
 *
 * ┌─────────────────────────────────────────────────────────────────────────┐
 * │  This is a deliberately naive first pass. It makes the simplest possible  │
 * │  decision and gets almost everything else wrong. Turning it into          │
 * │  something you would put your name on is the exercise — start from the    │
 * │  brief, the leave policy (docs/LEAVE_POLICY.md) and the seeded data.      │
 * └─────────────────────────────────────────────────────────────────────────┘
 */
final class LeaveRequestProcessor
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly LeaveRequestRepository $leaveRequests,
        private readonly LeaveBalanceRepository $leaveBalances,
        private readonly HrApiClientInterface $hrApi,
    ) {
    }

    /**
     * @return list<array{request: int, status: string, days: float}>
     */
    public function processPending(\DateTimeImmutable $runDate): array
    {
        $summary = [];

        foreach ($this->leaveRequests->findPending() as $request) {
            $decision = $this->decide($request, $runDate);

            $request->markDecided($decision->status, $runDate, $decision->reason);

            if (LeaveStatus::APPROVED === $decision->status) {
                $this->balanceFor($request)->addUsedDays($decision->consumedDays);
            }

            // Report the decision. NOTE: a fresh random key every time means a
            // re-run posts the same decision again as a brand-new record.
            $response = $this->hrApi->postDecision(
                [
                    'employeeId' => $request->getEmployee()->getId(),
                    'requestId' => $request->getId(),
                    'decision' => $decision->status->value,
                    'days' => $decision->consumedDays,
                    'reason' => $decision->reason,
                ],
                bin2hex(random_bytes(8)),
            );
            $request->setExternalReference(\is_string($response['id'] ?? null) ? $response['id'] : null);

            $summary[] = [
                'request' => (int) $request->getId(),
                'status' => $decision->status->value,
                'days' => $decision->consumedDays,
            ];
        }

        // Everything is committed in one shot, at the very end.
        $this->entityManager->flush();

        return $summary;
    }

    /**
     * Decide a single request.
     *
     * Naive rules — intentionally incomplete:
     *   - every calendar day in the range counts as one leave day
     *   - every request type is treated the same
     *   - remaining = contractual + carried-over − used, ignoring the calendar
     */
    private function decide(LeaveRequest $request, \DateTimeImmutable $runDate): Decision
    {
        $consumed = (float) ($request->getStartDate()->diff($request->getEndDate())->days + 1);

        $balance = $this->balanceFor($request);
        $remaining = $request->getEmployee()->getContractualLeaveDays()
            + $balance->getCarriedOverDays()
            - $balance->getUsedDays();

        if ($consumed <= $remaining) {
            return new Decision(LeaveStatus::APPROVED, $consumed, 'within balance');
        }

        return new Decision(LeaveStatus::REJECTED, 0.0, 'insufficient balance');
    }

    private function balanceFor(LeaveRequest $request): LeaveBalance
    {
        $year = (int) $request->getStartDate()->format('Y');
        $balance = $this->leaveBalances->findForEmployeeAndYear($request->getEmployee(), $year);

        if (null === $balance) {
            throw new \RuntimeException(sprintf(
                'No leave balance for employee #%d in %d.',
                (int) $request->getEmployee()->getId(),
                $year,
            ));
        }

        return $balance;
    }
}
