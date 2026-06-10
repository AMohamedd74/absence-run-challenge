<?php

declare(strict_types=1);

namespace App\Leave\Policy;

use App\Entity\Employee;

/**
 * Annual leave entitlement in working days (LEAVE_POLICY.md §1–3).
 *
 * entitlement = contractualLeaveDays
 *             × (full months employed in the year) / 12   (pro-rata, §2)
 *             × workingDaysPerWeek / 5                     (part-time, §3)
 * rounded up to the nearest half-day, once, at the end.
 *
 * §1 guarantees `contractualLeaveDays` is already at least the statutory minimum,
 * so the value is trusted as given rather than clamped (clamping would only ever
 * fire on data that violates §1, and silently rewriting a payroll figure is worse
 * than surfacing it as a data error elsewhere).
 */
final class EntitlementCalculator
{
    public function forEmployee(Employee $employee, int $year): float
    {
        $months = $this->fullMonthsEmployed($employee, $year);

        $raw = $employee->getContractualLeaveDays()
            * $months / 12
            * $employee->getWorkingDaysPerWeek() / 5;

        return $this->roundUpToHalf($raw);
    }

    /**
     * Calendar months of the year the employee is employed for the whole month (§2).
     * A joiner on the 1st earns that month; a leaver through month-end earns it.
     */
    private function fullMonthsEmployed(Employee $employee, int $year): int
    {
        $yearStart = new \DateTimeImmutable("$year-01-01");
        $yearEnd = new \DateTimeImmutable("$year-12-31");

        $effectiveStart = max($employee->getEmploymentStartDate(), $yearStart);
        $effectiveEnd = min($employee->getEmploymentEndDate() ?? $yearEnd, $yearEnd);

        $count = 0;
        for ($m = 1; $m <= 12; $m++) {
            $monthStart = new \DateTimeImmutable(sprintf('%d-%02d-01', $year, $m));
            $monthEnd = $monthStart->modify('last day of this month');
            if ($effectiveStart <= $monthStart && $effectiveEnd >= $monthEnd) {
                $count++;
            }
        }

        return $count;
    }

    private function roundUpToHalf(float $value): float
    {
        // Subtract a tiny epsilon so float representation error (e.g. 13.0000000002
        // from the chained ÷12 ÷5) doesn't spuriously round a whole/half result up
        // by an extra half-day.
        return ceil($value * 2 - 1e-9) / 2;
    }
}
