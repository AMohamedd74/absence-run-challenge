<?php

declare(strict_types=1);

namespace App\Leave\AbsenceRun;

use App\Entity\Decision;
use App\Entity\LeaveRequest;
use App\Enum\LeaveStatus;
use App\Hr\HrApiClientInterface;
use App\Leave\Decision\Evaluation;
use App\Leave\Policy\BalanceCalculator;
use App\Repository\DecisionRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Applies and reports a single decision, idempotently.
 *
 * The balance row is resolved BEFORE the HR call, then HR is posted, then the
 * balance and the persisted {@see Decision} are committed in one transaction — so a
 * crash between the HR post and the commit is safely retried (HR replays the key;
 * the balance is applied exactly once, on the run that commits) and a missing
 * balance fails before anything is posted to HR.
 */
final class DecisionRecorder
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly DecisionRepository $decisions,
        private readonly HrApiClientInterface $hrApi,
        private readonly BalanceCalculator $balances,
    ) {
    }

    /**
     * Record a fresh decision (approve/reject) for a pending request, marking the
     * request decided.
     *
     * @return array{request: int, status: string, days: float, reason: string}
     */
    public function record(LeaveRequest $request, Evaluation $evaluation, \DateTimeImmutable $runDate): array
    {
        $key = sprintf('%d:%s', (int) $request->getId(), $evaluation->status->value);

        if (null !== $this->decisions->findByKey($key)) {
            // Fresh decisions only ever come from PENDING requests, which have no
            // prior decision — a hit means the request was re-opened after a terminal
            // decision (unsupported). Surface it loudly rather than silently dedupe.
            throw new \LogicException(sprintf(
                'Decision "%s" already exists; request #%d appears to have been re-opened.',
                $key,
                (int) $request->getId(),
            ));
        }

        return $this->commit($request, $evaluation, $key, $runDate, markRequest: true);
    }

    /**
     * Reverse an earlier approval (§12) — credit days back and post a `cancelled`
     * decision to HR — without touching the request's status (it is already
     * cancelled, or, for a §9 credit revocation, the sick request stays approved).
     * A request already reversed is a no-op, so cascades and re-runs are safe.
     *
     * @return array{request: int, status: string, days: float, reason: string}|null
     */
    public function recordReversal(LeaveRequest $request, float $balanceDelta, string $reason, \DateTimeImmutable $runDate): ?array
    {
        if ($this->decisions->hasReversal($request)) {
            return null;
        }

        $key = sprintf('%d:%s', (int) $request->getId(), LeaveStatus::CANCELLED->value);

        return $this->commit($request, Evaluation::reversed($balanceDelta, $reason), $key, $runDate, markRequest: false);
    }

    /**
     * @return array{request: int, status: string, days: float, reason: string}
     */
    private function commit(
        LeaveRequest $request,
        Evaluation $evaluation,
        string $key,
        \DateTimeImmutable $runDate,
        bool $markRequest,
    ): array {
        // Resolve the balance first: a missing balance throws here, before the HR post.
        $balance = 0.0 !== $evaluation->balanceDelta ? $this->balances->balanceFor($request) : null;

        $response = $this->hrApi->postDecision((new DecisionPayload($request, $evaluation))->toArray(), $key);
        $externalReference = \is_string($response['id'] ?? null) ? $response['id'] : null;

        $decision = new Decision(
            $request,
            $evaluation->status,
            $request->getType(),
            $evaluation->balanceDelta,
            $evaluation->reason,
            $key,
            $runDate,
        );
        $decision->setExternalReference($externalReference);
        $this->entityManager->persist($decision);

        $balance?->addUsedDays($evaluation->balanceDelta);

        if ($markRequest) {
            $request->markDecided($evaluation->status, $runDate, $evaluation->reason);
            $request->setExternalReference($externalReference);
        }

        $this->entityManager->flush();

        return [
            'request' => (int) $request->getId(),
            'status' => $evaluation->status->value,
            'days' => $evaluation->balanceDelta,
            'reason' => $evaluation->reason,
        ];
    }
}
