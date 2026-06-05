<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\EmployeeRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: EmployeeRepository::class)]
class Employee
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    public function __construct(
        #[ORM\Column(length: 255)]
        private string $name,

        /** First day of employment. */
        #[ORM\Column(type: Types::DATE_IMMUTABLE)]
        private \DateTimeImmutable $employmentStartDate,

        /** Contracted working days per week (5 = full week, 3 = part-time, …). */
        #[ORM\Column]
        private int $workingDaysPerWeek,

        /** German federal state (Bundesland) code, e.g. "BY", "BE" — determines public holidays. */
        #[ORM\Column(length: 2)]
        private string $federalState,

        /** Contractual annual leave in working days for a full year on a five-day week. */
        #[ORM\Column]
        private int $contractualLeaveDays,

        /** Last day of employment, or null if still employed. */
        #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
        private ?\DateTimeImmutable $employmentEndDate = null,
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getEmploymentStartDate(): \DateTimeImmutable
    {
        return $this->employmentStartDate;
    }

    public function getEmploymentEndDate(): ?\DateTimeImmutable
    {
        return $this->employmentEndDate;
    }

    public function getWorkingDaysPerWeek(): int
    {
        return $this->workingDaysPerWeek;
    }

    public function getFederalState(): string
    {
        return $this->federalState;
    }

    public function getContractualLeaveDays(): int
    {
        return $this->contractualLeaveDays;
    }
}
