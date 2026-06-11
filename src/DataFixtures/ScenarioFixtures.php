<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\Employee;
use App\Entity\LeaveBalance;
use App\Entity\LeaveRequest;
use App\Enum\LeaveStatus;
use App\Enum\LeaveType;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Bundle\FixturesBundle\FixtureGroupInterface;
use Doctrine\Persistence\ObjectManager;

/**
 * An edge-case catalogue for eyeballing a run, kept separate from the lean
 * "realistic period" of {@see AppFixtures} so each tells a clean story. Every
 * scenario is one independent employee (balances don't interact) and is asserted
 * end-to-end by ScenarioFixturesTest, so it can't silently drift.
 *
 *   php bin/console doctrine:fixtures:load --group=scenarios
 *   php bin/console app:absence:run --date=2025-04-15
 *
 * Expected outcomes are noted per scenario; the run date is 2025-04-15.
 */
final class ScenarioFixtures extends Fixture implements FixtureGroupInterface
{
    private const int LEAVE_YEAR = 2025;

    public static function getGroups(): array
    {
        return ['scenarios'];
    }

    #[\Override]
    public function load(ObjectManager $manager): void
    {
        // --- Sick leave matrix --------------------------------------------------

        // Sick, no certificate, ≤3 calendar days → recorded, no balance impact.
        $e = $this->employee($manager, 'Sick NoCert Short', 5, 'BE', 28, used: 0.0);
        $this->request($manager, $e, LeaveType::SICK, '2025-05-19', '2025-05-21', '2025-04-02');

        // Sick, no certificate, >3 calendar days → rejected (certificate required).
        $e = $this->employee($manager, 'Sick NoCert Long', 5, 'BE', 28, used: 0.0);
        $this->request($manager, $e, LeaveType::SICK, '2025-05-19', '2025-05-23', '2025-04-02');

        // Sick, certificate, long, no overlapping vacation → recorded, no impact.
        $e = $this->employee($manager, 'Sick Cert NoOverlap', 5, 'BE', 28, used: 0.0);
        $this->request($manager, $e, LeaveType::SICK, '2025-05-19', '2025-05-26', '2025-04-02', certificate: true);

        // Sick, certificate, overlapping an approved vacation → §9 credit of the
        // 2 overlapping working days: used 5 → 3.
        $e = $this->employee($manager, 'Sick Cert Overlap', 5, 'BY', 30, used: 5.0);
        $this->approvedVacation($manager, $e, '2025-03-17', '2025-03-21');
        $this->request($manager, $e, LeaveType::SICK, '2025-03-18', '2025-03-19', '2025-04-02', certificate: true);

        // Sick overlapping a HALF-DAY vacation → credit mirrors consumption (Mon=0.5
        // + Tue=1.0 = 1.5, not 2): used 4.5 → 3.0.
        $e = $this->employee($manager, 'Sick Cert HalfDay Overlap', 5, 'BE', 28, used: 4.5);
        $this->approvedVacation($manager, $e, '2025-03-17', '2025-03-21', halfDayStart: true);
        $this->request($manager, $e, LeaveType::SICK, '2025-03-17', '2025-03-18', '2025-04-02', certificate: true);

        // --- Other absence types ------------------------------------------------

        // Unpaid → recorded, no impact.
        $e = $this->employee($manager, 'Unpaid', 5, 'BE', 28, used: 0.0);
        $this->request($manager, $e, LeaveType::UNPAID, '2025-05-05', '2025-05-09', '2025-04-02');

        // Special → always approved, no impact.
        $e = $this->employee($manager, 'Special', 5, 'BE', 28, used: 0.0);
        $this->request($manager, $e, LeaveType::SPECIAL, '2025-06-02', '2025-06-02', '2025-04-02');

        // --- Balance & overlap --------------------------------------------------

        // Insufficient balance (28 entitlement, 26 used → 2 left) for a 5-day request → rejected.
        $e = $this->employee($manager, 'Insufficient', 5, 'BE', 28, used: 26.0);
        $this->request($manager, $e, LeaveType::VACATION, '2025-05-19', '2025-05-23', '2025-04-02');

        // Two overlapping pending vacations → first approved (+5), second rejected (§10).
        $e = $this->employee($manager, 'Overlap', 5, 'BE', 28, used: 0.0);
        $this->request($manager, $e, LeaveType::VACATION, '2025-05-19', '2025-05-23', '2025-04-01');
        $this->request($manager, $e, LeaveType::VACATION, '2025-05-21', '2025-05-27', '2025-04-02');

        // Two vacations sharing only a weekend → both approved (no working-day overlap): used 1 + 2 = 3.
        $e = $this->employee($manager, 'Weekend Overlap', 5, 'BY', 28, used: 0.0);
        $this->request($manager, $e, LeaveType::VACATION, '2025-07-04', '2025-07-06', '2025-04-01'); // Fri–Sun
        $this->request($manager, $e, LeaveType::VACATION, '2025-07-06', '2025-07-08', '2025-04-02'); // Sun–Tue

        // --- Leaver with an over-drawn balance ----------------------------------

        // Pro-rata entitlement for a leaver ending 30 June is 24 × 6/12 = 12, but
        // 20 days were already used → remaining is −8. The balance is over-drawn
        // (flagged, not clawed back), and any further request is rejected.
        $e = $this->employee($manager, 'Leaver Overdrawn', 5, 'BE', 24, used: 20.0, employmentEnd: '2025-06-30');
        $this->request($manager, $e, LeaveType::VACATION, '2025-06-16', '2025-06-20', '2025-04-02');

        // --- Bad data -----------------------------------------------------------

        // Invalid workingDaysPerWeek (7) → rejected by validation before evaluation.
        $e = $this->employee($manager, 'Bad WorkingDays', 7, 'BE', 28, used: 0.0);
        $this->request($manager, $e, LeaveType::VACATION, '2025-05-19', '2025-05-23', '2025-04-02');

        $manager->flush();
    }

    private function employee(
        ObjectManager $manager,
        string $name,
        int $workingDaysPerWeek,
        string $federalState,
        int $contractualLeaveDays,
        float $used,
        ?string $employmentEnd = null,
    ): Employee {
        $employee = new Employee(
            $name,
            new \DateTimeImmutable('2018-01-01'),
            $workingDaysPerWeek,
            $federalState,
            $contractualLeaveDays,
            $employmentEnd ? new \DateTimeImmutable($employmentEnd) : null,
        );
        $manager->persist($employee);
        $manager->persist(new LeaveBalance($employee, self::LEAVE_YEAR, 0.0, null, $used));

        return $employee;
    }

    private function approvedVacation(
        ObjectManager $manager,
        Employee $employee,
        string $start,
        string $end,
        bool $halfDayStart = false,
    ): void {
        $vacation = new LeaveRequest(
            $employee,
            LeaveType::VACATION,
            new \DateTimeImmutable($start),
            new \DateTimeImmutable($end),
            new \DateTimeImmutable('2025-02-01'),
        );
        $vacation->setHalfDayStart($halfDayStart);
        $vacation->markDecided(LeaveStatus::APPROVED, new \DateTimeImmutable('2025-02-15'), 'within balance');
        $manager->persist($vacation);
    }

    private function request(
        ObjectManager $manager,
        Employee $employee,
        LeaveType $type,
        string $start,
        string $end,
        string $submittedAt,
        bool $halfDayStart = false,
        bool $certificate = false,
    ): void {
        $request = new LeaveRequest(
            $employee,
            $type,
            new \DateTimeImmutable($start),
            new \DateTimeImmutable($end),
            new \DateTimeImmutable($submittedAt),
        );
        $request->setHalfDayStart($halfDayStart)->setMedicalCertificate($certificate);
        $manager->persist($request);
    }
}
