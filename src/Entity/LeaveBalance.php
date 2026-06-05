<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\LeaveBalanceRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Opening leave balance for one employee in one calendar year.
 *
 * This is the state of the world at the start of the run: how many days were
 * carried over from last year, when that carryover lapses, and how many days
 * have already been consumed this year.
 */
#[ORM\Entity(repositoryClass: LeaveBalanceRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_employee_year', columns: ['employee_id', 'year'])]
class LeaveBalance
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false)]
        private Employee $employee,

        #[ORM\Column]
        private int $year,

        /** Days carried over from the previous year. */
        #[ORM\Column(type: Types::FLOAT)]
        private float $carriedOverDays = 0.0,

        /** Date on which carried-over days lapse, or null if they do not. */
        #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
        private ?\DateTimeImmutable $carryoverExpiresOn = null,

        /** Vacation days already consumed this year before this run. */
        #[ORM\Column(type: Types::FLOAT)]
        private float $usedDays = 0.0,
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

    public function getYear(): int
    {
        return $this->year;
    }

    public function getCarriedOverDays(): float
    {
        return $this->carriedOverDays;
    }

    public function getCarryoverExpiresOn(): ?\DateTimeImmutable
    {
        return $this->carryoverExpiresOn;
    }

    public function getUsedDays(): float
    {
        return $this->usedDays;
    }

    public function setUsedDays(float $usedDays): void
    {
        $this->usedDays = $usedDays;
    }

    public function addUsedDays(float $days): void
    {
        $this->usedDays += $days;
    }
}
