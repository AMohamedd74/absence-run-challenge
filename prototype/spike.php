<?php

declare(strict_types=1);

/**
 * THROWAWAY SPIKE — not production code.
 *
 * De-risks the two parts of the spec with the most arithmetic edge cases:
 *   1. the working-day counter (weekends + state holidays + half-days)
 *   2. entitlement (statutory clamp × pro-rata × part-time, round up to half-day)
 *
 * It asserts both against the seed oracle in SPEC.md. Run: php prototype/spike.php
 */

// --- Public holidays 2025 (LEAVE_POLICY.md §5) ------------------------------
const HOLIDAYS = [
    'BY' => [
        '2025-01-01', '2025-01-06', '2025-04-18', '2025-04-21', '2025-05-01',
        '2025-05-29', '2025-06-09', '2025-06-19', '2025-08-15', '2025-10-03',
        '2025-11-01', '2025-12-25', '2025-12-26',
    ],
    'BE' => [
        '2025-01-01', '2025-03-08', '2025-04-18', '2025-04-21', '2025-05-01',
        '2025-05-29', '2025-06-09', '2025-10-03', '2025-12-25', '2025-12-26',
    ],
];

function isWorkingDay(DateTimeImmutable $d, string $state): bool
{
    $dow = (int) $d->format('N');          // 1 = Mon … 7 = Sun
    if ($dow >= 6) {
        return false;                       // weekend
    }

    return !in_array($d->format('Y-m-d'), HOLIDAYS[$state], true);
}

/**
 * Working days consumed by a request (§4): working days in [start, end],
 * minus 0.5 per half-day flag (only when that boundary is a working day).
 * A single-day request flagged both halves counts as 0.5.
 */
function consumedDays(
    string $start,
    string $end,
    string $state,
    bool $halfStart = false,
    bool $halfEnd = false,
): float {
    $s = new DateTimeImmutable($start);
    $e = new DateTimeImmutable($end);

    $count = 0.0;
    for ($d = $s; $d <= $e; $d = $d->modify('+1 day')) {
        if (isWorkingDay($d, $state)) {
            $count += 1.0;
        }
    }

    // Single-day request with both half flags → 0.5 (not 1 − 0.5 − 0.5 = 0).
    if ($s == $e && $halfStart && $halfEnd) {
        return isWorkingDay($s, $state) ? 0.5 : 0.0;
    }

    if ($halfStart && isWorkingDay($s, $state)) {
        $count -= 0.5;
    }
    if ($halfEnd && isWorkingDay($e, $state)) {
        $count -= 0.5;
    }

    return $count;
}

/** Working days that two ranges share (for the §9 sick-during-vacation credit). */
function overlapWorkingDays(string $aStart, string $aEnd, string $bStart, string $bEnd, string $state): float
{
    $start = max(new DateTimeImmutable($aStart), new DateTimeImmutable($bStart));
    $end = min(new DateTimeImmutable($aEnd), new DateTimeImmutable($bEnd));
    if ($start > $end) {
        return 0.0;
    }

    return consumedDays($start->format('Y-m-d'), $end->format('Y-m-d'), $state);
}

function roundUpToHalf(float $x): float
{
    return ceil($x * 2) / 2;
}

/** Calendar months of `year` the employee is employed in their entirety (§2). */
function fullMonthsEmployed(string $employmentStart, ?string $employmentEnd, int $year): int
{
    $yearStart = new DateTimeImmutable("$year-01-01");
    $yearEnd = new DateTimeImmutable("$year-12-31");
    $effStart = max(new DateTimeImmutable($employmentStart), $yearStart);
    $effEnd = min($employmentEnd ? new DateTimeImmutable($employmentEnd) : $yearEnd, $yearEnd);

    $count = 0;
    for ($m = 1; $m <= 12; $m++) {
        $monthStart = new DateTimeImmutable(sprintf('%d-%02d-01', $year, $m));
        $monthEnd = $monthStart->modify('last day of this month');
        if ($effStart <= $monthStart && $effEnd >= $monthEnd) {
            $count++;
        }
    }

    return $count;
}

/** Annual entitlement: statutory floor, then pro-rata × part-time, rounded once. */
function entitlement(
    int $contractual,
    int $workingDaysPerWeek,
    string $employmentStart,
    ?string $employmentEnd,
    int $year,
): float {
    // §1 guarantees contractual ≥ statutory; trusted as-is (no clamp — see SPEC).
    $months = fullMonthsEmployed($employmentStart, $employmentEnd, $year);
    $value = $contractual * $months / 12 * $workingDaysPerWeek / 5;

    return roundUpToHalf($value);
}

// ---------------------------------------------------------------------------
// Assertions against the SPEC oracle.
// ---------------------------------------------------------------------------
$failures = 0;
$check = static function (string $label, float $got, float $want) use (&$failures): void {
    $ok = abs($got - $want) < 1e-9;
    printf("  [%s] %-46s got %-5s want %-5s\n", $ok ? 'OK' : 'XX', $label, $got, $want);
    if (!$ok) {
        $failures++;
    }
};

echo "Working-day counts\n";
$check('Anna VAC 05-19→23 BY', consumedDays('2025-05-19', '2025-05-23', 'BY'), 5.0);
$check('Anna VAC 04-28→30 BY ½-start', consumedDays('2025-04-28', '2025-04-30', 'BY', true), 2.5);
$check('Eva VAC 06-05→11 BE ½-start (Whit Mon)', consumedDays('2025-06-05', '2025-06-11', 'BE', true), 3.5);
$check('Felix VAC 05-26→30 BE (Ascension)', consumedDays('2025-05-26', '2025-05-30', 'BE'), 4.0);
$check('Carla/Bjarne VAC 07-07→11 BE', consumedDays('2025-07-07', '2025-07-11', 'BE'), 5.0);
$check('Dilan March vac 03-17→28 BY', consumedDays('2025-03-17', '2025-03-28', 'BY'), 10.0);

echo "\nEdge cases\n";
$check('½-start on a weekend (Sat 06-07)', consumedDays('2025-06-07', '2025-06-07', 'BE', true), 0.0);
$check('single day, both halves (Tue 07-08)', consumedDays('2025-07-08', '2025-07-08', 'BE', true, true), 0.5);
$check('all-weekend range (Sat–Sun)', consumedDays('2025-06-07', '2025-06-08', 'BE'), 0.0);

echo "\n§9 sick overlap (Dilan)\n";
$check('SICK 03-24→26 ∩ vac 03-17→28 BY', overlapWorkingDays('2025-03-24', '2025-03-26', '2025-03-17', '2025-03-28', 'BY'), 3.0);

echo "\nEntitlement\n";
$check('Anna full-time full-year (28)', entitlement(28, 5, '2018-06-01', null, 2025), 28.0);
$check('Bjarne part-time 3/5 (28→17)', entitlement(28, 3, '2017-01-01', null, 2025), 17.0);
$check('Carla joiner 03-01 (30→25)', entitlement(30, 5, '2025-03-01', null, 2025), 25.0);
$check('Below-floor contractual trusted (10)', entitlement(10, 5, '2018-01-01', null, 2025), 10.0);
$check('Leaver ends 06-30 full-time (28→14)', entitlement(28, 5, '2015-01-01', '2025-06-30', 2025), 14.0);

echo "\n" . ($failures === 0 ? "ALL PASS\n" : "$failures FAILURE(S)\n");
exit($failures === 0 ? 0 : 1);
