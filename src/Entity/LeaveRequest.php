<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\LeaveStatus;
use App\Enum\LeaveType;
use App\Repository\LeaveRequestRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: LeaveRequestRepository::class)]
class LeaveRequest
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(enumType: LeaveStatus::class)]
    private LeaveStatus $status = LeaveStatus::PENDING;

    /** True when the first day of the range is taken as a half day. */
    #[ORM\Column]
    private bool $halfDayStart = false;

    /** True when the last day of the range is taken as a half day. */
    #[ORM\Column]
    private bool $halfDayEnd = false;

    /** Whether a doctor's note backs this request (relevant for sick leave). */
    #[ORM\Column]
    private bool $medicalCertificate = false;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $decidedAt = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $decisionReason = null;

    /** Identifier returned by the HR system once the decision has been posted. */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $externalReference = null;

    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false)]
        private Employee $employee,

        #[ORM\Column(enumType: LeaveType::class)]
        private LeaveType $type,

        #[ORM\Column(type: Types::DATE_IMMUTABLE)]
        private \DateTimeImmutable $startDate,

        #[ORM\Column(type: Types::DATE_IMMUTABLE)]
        private \DateTimeImmutable $endDate,

        #[ORM\Column(type: Types::DATE_IMMUTABLE)]
        private \DateTimeImmutable $submittedAt,
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEmployee(): Employee
    {
        return $this->employee;
    }

    public function getType(): LeaveType
    {
        return $this->type;
    }

    public function getStartDate(): \DateTimeImmutable
    {
        return $this->startDate;
    }

    public function getEndDate(): \DateTimeImmutable
    {
        return $this->endDate;
    }

    public function getSubmittedAt(): \DateTimeImmutable
    {
        return $this->submittedAt;
    }

    public function getStatus(): LeaveStatus
    {
        return $this->status;
    }

    public function setStatus(LeaveStatus $status): self
    {
        $this->status = $status;

        return $this;
    }

    public function isHalfDayStart(): bool
    {
        return $this->halfDayStart;
    }

    public function setHalfDayStart(bool $halfDayStart): self
    {
        $this->halfDayStart = $halfDayStart;

        return $this;
    }

    public function isHalfDayEnd(): bool
    {
        return $this->halfDayEnd;
    }

    public function setHalfDayEnd(bool $halfDayEnd): self
    {
        $this->halfDayEnd = $halfDayEnd;

        return $this;
    }

    public function hasMedicalCertificate(): bool
    {
        return $this->medicalCertificate;
    }

    public function setMedicalCertificate(bool $medicalCertificate): self
    {
        $this->medicalCertificate = $medicalCertificate;

        return $this;
    }

    public function getDecidedAt(): ?\DateTimeImmutable
    {
        return $this->decidedAt;
    }

    public function getDecisionReason(): ?string
    {
        return $this->decisionReason;
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

    /** Record a final decision on this request. */
    public function markDecided(LeaveStatus $status, \DateTimeImmutable $on, ?string $reason): self
    {
        $this->status = $status;
        $this->decidedAt = $on;
        $this->decisionReason = $reason;

        return $this;
    }
}
