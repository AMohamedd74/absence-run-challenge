<?php

declare(strict_types=1);

namespace App\Leave\Policy;

/**
 * Public holidays per federal state and year (LEAVE_POLICY.md §5).
 *
 * Data-driven: a real deployment would load these from a table or a holidays
 * service. Only the two states and the one year used by the sample are populated;
 * an unknown (state, year) yields no holidays — see {@see holidaysFor()}.
 */
final class HolidayCalendar
{
    /** @var array<int, array<string, list<string>>> year => state => Y-m-d dates */
    private const HOLIDAYS = [
        2025 => [
            'BY' => [
                '2025-01-01', '2025-01-06', '2025-04-18', '2025-04-21', '2025-05-01',
                '2025-05-29', '2025-06-09', '2025-06-19', '2025-08-15', '2025-10-03',
                '2025-11-01', '2025-12-25', '2025-12-26',
            ],
            'BE' => [
                '2025-01-01', '2025-03-08', '2025-04-18', '2025-04-21', '2025-05-01',
                '2025-05-29', '2025-06-09', '2025-10-03', '2025-12-25', '2025-12-26',
            ],
        ],
    ];

    public function isPublicHoliday(\DateTimeImmutable $date, string $state): bool
    {
        return \in_array($date->format('Y-m-d'), $this->holidaysFor((int) $date->format('Y'), $state), true);
    }

    /**
     * A working day is a weekday that is not a public holiday in the employee's state.
     */
    public function isWorkingDay(\DateTimeImmutable $date, string $state): bool
    {
        $isWeekend = (int) $date->format('N') >= 6; // 6 = Sat, 7 = Sun

        return !$isWeekend && !$this->isPublicHoliday($date, $state);
    }

    /** @return list<string> */
    private function holidaysFor(int $year, string $state): array
    {
        return self::HOLIDAYS[$year][$state] ?? [];
    }
}
