<?php

declare(strict_types=1);

namespace App\Tests\Leave\Policy;

use App\Entity\Employee;
use App\Entity\LeaveRequest;
use App\Enum\LeaveType;
use App\Leave\Policy\HolidayCalendar;
use App\Leave\Policy\WorkingDayCounter;
use PHPUnit\Framework\TestCase;

final class WorkingDayCounterTest extends TestCase
{
    private WorkingDayCounter $counter;

    #[\Override]
    protected function setUp(): void
    {
        $this->counter = new WorkingDayCounter(new HolidayCalendar());
    }

    public function testPlainWorkingWeek(): void
    {
        self::assertSame(5.0, $this->days('2025-05-19', '2025-05-23', 'BY'));
    }

    public function testWeekendsAreNotCounted(): void
    {
        self::assertSame(0.0, $this->days('2025-06-07', '2025-06-08', 'BE')); // Sat–Sun
    }

    public function testPublicHolidayIsExcludedPerState(): void
    {
        // 2025-05-29 (Ascension) is a holiday in both BE and BY.
        self::assertSame(4.0, $this->days('2025-05-26', '2025-05-30', 'BE'));
        // 2025-06-09 (Whit Monday) is a holiday in BE.
        self::assertSame(4.0, $this->days('2025-06-09', '2025-06-13', 'BE'));
    }

    public function testHolidayDiffersByState(): void
    {
        // 2025-06-19 (Corpus Christi) is a holiday in BY but not in BE.
        self::assertSame(4.0, $this->days('2025-06-16', '2025-06-20', 'BY'));
        self::assertSame(5.0, $this->days('2025-06-16', '2025-06-20', 'BE'));
    }

    public function testHalfDayStartSubtractsHalf(): void
    {
        self::assertSame(2.5, $this->days('2025-04-28', '2025-04-30', 'BY', halfStart: true));
    }

    public function testHalfDayOnAWeekendContributesNothing(): void
    {
        self::assertSame(0.0, $this->days('2025-06-07', '2025-06-07', 'BE', halfStart: true)); // Saturday
    }

    public function testSingleDayWithBothHalvesIsHalfADay(): void
    {
        self::assertSame(0.5, $this->days('2025-07-08', '2025-07-08', 'BE', halfStart: true, halfEnd: true));
    }

    public function testOverlapWorkingDays(): void
    {
        $sick = $this->request('2025-03-24', '2025-03-26', 'BY');
        $vacation = $this->request('2025-03-17', '2025-03-28', 'BY');
        self::assertSame(3.0, $this->counter->overlapWorkingDays($sick, $vacation));
    }

    public function testNoOverlapReturnsZero(): void
    {
        $a = $this->request('2025-03-03', '2025-03-07', 'BY');
        $b = $this->request('2025-03-17', '2025-03-21', 'BY');
        self::assertSame(0.0, $this->counter->overlapWorkingDays($a, $b));
    }

    public function testRangesSharingOnlyAWeekendHaveNoWorkingOverlap(): void
    {
        // Fri–Sun and Sun–Tue share only Sunday → no common working day (§10 must not block).
        $a = $this->request('2025-06-06', '2025-06-08', 'BE'); // Fri–Sun
        $b = $this->request('2025-06-08', '2025-06-10', 'BE'); // Sun–Tue
        self::assertSame(0.0, $this->counter->overlapWorkingDays($a, $b));
    }

    public function testOverlapMirrorsTheVacationHalfDayConsumption(): void
    {
        // A Mon–Fri vacation with a half-day start consumes 4.5; a sick period covering
        // the whole vacation must credit 4.5, not 5 — it cannot return more than was consumed.
        $sick = $this->request('2025-07-07', '2025-07-11', 'BE');
        $vacation = $this->request('2025-07-07', '2025-07-11', 'BE')->setHalfDayStart(true);
        self::assertSame(4.5, $this->counter->overlapWorkingDays($sick, $vacation));
    }

    private function days(string $start, string $end, string $state, bool $halfStart = false, bool $halfEnd = false): float
    {
        return $this->counter->count(
            new \DateTimeImmutable($start),
            new \DateTimeImmutable($end),
            $state,
            $halfStart,
            $halfEnd,
        );
    }

    private function request(string $start, string $end, string $state): LeaveRequest
    {
        $employee = new Employee('T', new \DateTimeImmutable('2018-01-01'), 5, $state, 28);

        return new LeaveRequest(
            $employee,
            LeaveType::VACATION,
            new \DateTimeImmutable($start),
            new \DateTimeImmutable($end),
            new \DateTimeImmutable('2025-01-01'),
        );
    }
}
