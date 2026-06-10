<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\LeaveStatus;
use App\Enum\LeaveType;
use App\Repository\DecisionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A durable record of one decision the run posted to the HR system.
 *
 * Persisting decisions (rather than only mutating balances) is what makes the run
 * idempotent and reversible: the unique `idempotencyKey` is both the HR
 * Idempotency-Key and the guard that a decision was already applied, and a
 * `CANCELLED` decision records a §12 reversal of an earlier approval.
 */
#[ORM\Entity(repositoryClass: DecisionRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_decision_key', columns: ['idempotency_key'])]
class Decision
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Identifier returned by the HR system for this decision. */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $externalReference = null;

    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false)]
        private LeaveRequest $request,

        #[ORM\Column(enumType: LeaveStatus::class)]
        private LeaveStatus $status,

        #[ORM\Column(enumType: LeaveType::class)]
        private LeaveType $type,

        /** Days added to the vacation balance: positive consumes, negative credits/reverses, 0 = no impact. */
        #[ORM\Column(type: Types::FLOAT)]
        private float $balanceDelta,

        #[ORM\Column(length: 255)]
        private string $reason,

        #[ORM\Column(length: 255)]
        private string $idempotencyKey,

        #[ORM\Column(type: Types::DATE_IMMUTABLE)]
        private \DateTimeImmutable $decidedOn,
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getRequest(): LeaveRequest
    {
        return $this->request;
    }

    public function getStatus(): LeaveStatus
    {
        return $this->status;
    }

    public function getType(): LeaveType
    {
        return $this->type;
    }

    public function getBalanceDelta(): float
    {
        return $this->balanceDelta;
    }

    public function getReason(): string
    {
        return $this->reason;
    }

    public function getIdempotencyKey(): string
    {
        return $this->idempotencyKey;
    }

    public function getDecidedOn(): \DateTimeImmutable
    {
        return $this->decidedOn;
    }

    public function getExternalReference(): ?string
    {
        return $this->externalReference;
    }

    public function setExternalReference(?string $externalReference): self
    {
        $this->externalReference = $externalReference;

        return $this;
    }
}
