<?php

declare(strict_types=1);

namespace App\Leave\Policy;

use App\Entity\LeaveRequest;
use App\Enum\LeaveType;
use App\Repository\LeaveRequestRepository;

/**
 * Overlap of a request with the employee's already-approved vacations — used both
 * to reject double-booked vacations (§10) and to size the §9 sick-during-vacation
 * credit. Reflects vacations approved earlier in the same run (the processor
 * commits per request).
 */
final class OverlapChecker
{
    public function __construct(
        private readonly LeaveRequestRepository $requests,
        private readonly WorkingDayCounter $workingDays,
    ) {
    }

    public function overlapsApprovedVacation(LeaveRequest $request): bool
    {
        foreach ($this->approvedVacations($request) as $approved) {
            // §10 forbids overlapping *leave* periods — two ranges that share only a
            // weekend or public holiday have no working day in common and don't clash.
            if ($this->workingDays->overlapWorkingDays($request, $approved) > 0.0) {
                return true;
            }
        }

        return false;
    }

    /** Total working days the request shares with the employee's approved vacations. */
    public function approvedVacationOverlapDays(LeaveRequest $request): float
    {
        $overlap = 0.0;
        foreach ($this->approvedVacations($request) as $vacation) {
            $overlap += $this->workingDays->overlapWorkingDays($request, $vacation);
        }

        return $overlap;
    }

    /** @return list<LeaveRequest> approved vacations of the employee, excluding the request itself */
    private function approvedVacations(LeaveRequest $request): array
    {
        $vacations = $this->requests->findApprovedByType($request->getEmployee(), LeaveType::VACATION);

        return array_values(array_filter(
            $vacations,
            static fn (LeaveRequest $v): bool => $v->getId() !== $request->getId(),
        ));
    }
}
