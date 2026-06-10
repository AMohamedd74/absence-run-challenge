<?php

declare(strict_types=1);

namespace App\Leave\Policy;

use App\Entity\LeaveBalance;
use App\Entity\LeaveRequest;
use App\Repository\LeaveBalanceRepository;

/**
 * Vacation-balance arithmetic: the remaining days available to a request, and
 * lookup of the balance row to mutate. Carryover that has lapsed by the run date
 * is excluded (§6); depletion order (§7) is implicit in a single remaining figure.
 */
final class BalanceCalculator
{
    /** @var array<string, LeaveBalance> per-run identity cache, keyed by employee+year */
    private array $cache = [];

    public function __construct(
        private readonly LeaveBalanceRepository $balances,
        private readonly EntitlementCalculator $entitlement,
    ) {
    }

    public function remainingFor(LeaveRequest $request, \DateTimeImmutable $runDate): float
    {
        $balance = $this->balanceFor($request);
        $entitlement = $this->entitlement->forEmployee($request->getEmployee(), $balance->getYear());

        return $entitlement + $this->validCarryover($balance, $runDate) - $balance->getUsedDays();
    }

    public function balanceFor(LeaveRequest $request): LeaveBalance
    {
        $employee = $request->getEmployee();
        $year = (int) $request->getStartDate()->format('Y');
        $key = $employee->getId().':'.$year;

        // The evaluator (remainingFor) and the recorder (applying the delta) both
        // need this row; cache the managed entity so it's fetched once per run.
        // The same object is mutated in place, so the cache stays consistent.
        if (!isset($this->cache[$key])) {
            $balance = $this->balances->findForEmployeeAndYear($employee, $year);
            if (null === $balance) {
                throw new \RuntimeException(sprintf(
                    'No leave balance for employee #%d in %d.',
                    (int) $employee->getId(),
                    $year,
                ));
            }
            $this->cache[$key] = $balance;
        }

        return $this->cache[$key];
    }

    /** Carried-over days that have not lapsed by the run date (§6). */
    private function validCarryover(LeaveBalance $balance, \DateTimeImmutable $runDate): float
    {
        $expiry = $balance->getCarryoverExpiresOn();
        if (null !== $expiry && $runDate > $expiry) {
            return 0.0;
        }

        return $balance->getCarriedOverDays();
    }
}
