<?php

declare(strict_types=1);

namespace App\Leave\Policy;

use App\Entity\LeaveRequest;

/**
 * Counts the working days a leave request consumes (LEAVE_POLICY.md §4):
 * working days in [start, end], excluding weekends and public holidays, minus
 * 0.5 for each half-day flag that lands on a working day.
 */
final class WorkingDayCounter
{
    public function __construct(private readonly HolidayCalendar $holidays)
    {
    }

    public function forRequest(LeaveRequest $request): float
    {
        return $this->count(
            $request->getStartDate(),
            $request->getEndDate(),
            $request->getEmployee()->getFederalState(),
            $request->isHalfDayStart(),
            $request->isHalfDayEnd(),
        );
    }

    public function count(
        \DateTimeImmutable $start,
        \DateTimeImmutable $end,
        string $state,
        bool $halfDayStart = false,
        bool $halfDayEnd = false,
    ): float {
        $days = 0.0;
        for ($d = $start; $d <= $end; $d = $d->modify('+1 day')) {
            if ($this->holidays->isWorkingDay($d, $state)) {
                $days += 1.0;
            }
        }

        // A single-day request flagged as both half-start and half-end describes one
        // half-day (0.5), not two halves netting to zero.
        if ($start == $end && $halfDayStart && $halfDayEnd) {
            return $this->holidays->isWorkingDay($start, $state) ? 0.5 : 0.0;
        }

        // A half-day flag only reduces the count when the boundary is itself a working day.
        if ($halfDayStart && $this->holidays->isWorkingDay($start, $state)) {
            $days -= 0.5;
        }
        if ($halfDayEnd && $this->holidays->isWorkingDay($end, $state)) {
            $days -= 0.5;
        }

        return $days;
    }

    /**
     * Days that `$period` (e.g. a sick request) shares with `$vacation`, measured as
     * the vacation's *own consumption* over the shared dates — so a §9 credit never
     * exceeds what the vacation actually deducted. The vacation's half-day flags are
     * mirrored only when its boundary day falls inside the overlap (that is exactly
     * when the overlap's boundary coincides with the vacation's, so passing them to
     * count() applies the 0.5 on the right day, single-day-both-halves included).
     */
    public function overlapWorkingDays(LeaveRequest $period, LeaveRequest $vacation): float
    {
        $start = max($period->getStartDate(), $vacation->getStartDate());
        $end = min($period->getEndDate(), $vacation->getEndDate());
        if ($start > $end) {
            return 0.0;
        }

        $halfStart = $vacation->isHalfDayStart()
            && $vacation->getStartDate() >= $start && $vacation->getStartDate() <= $end;
        $halfEnd = $vacation->isHalfDayEnd()
            && $vacation->getEndDate() >= $start && $vacation->getEndDate() <= $end;

        return $this->count($start, $end, $vacation->getEmployee()->getFederalState(), $halfStart, $halfEnd);
    }
}
