<?php

declare(strict_types=1);

namespace App\Leave\Decision;

use App\Entity\LeaveRequest;
use App\Enum\LeaveType;

/**
 * Precondition checks on a request before it is evaluated. Returns a rejection
 * reason for malformed data, or null when the request is well-formed. Malformed
 * data is rejected rather than silently corrected.
 */
final class LeaveRequestValidator
{
    public function validate(LeaveRequest $request): ?string
    {
        if ($request->getStartDate() > $request->getEndDate()) {
            return 'invalid date range (start after end)';
        }

        $year = (int) $request->getStartDate()->format('Y');
        if ((int) $request->getEndDate()->format('Y') !== $year) {
            return 'spans two leave years — please split';
        }

        $employee = $request->getEmployee();
        if ($request->getStartDate() < $employee->getEmploymentStartDate()) {
            return 'starts before employment';
        }
        $employmentEnd = $employee->getEmploymentEndDate();
        if (null !== $employmentEnd && $request->getEndDate() > $employmentEnd) {
            return 'ends after employment';
        }

        if (LeaveType::VACATION === $request->getType()) {
            $workingDaysPerWeek = $employee->getWorkingDaysPerWeek();
            if ($workingDaysPerWeek < 1 || $workingDaysPerWeek > 6) {
                return 'invalid working days per week';
            }
        }

        return null;
    }
}
