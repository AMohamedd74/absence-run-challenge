<?php

declare(strict_types=1);

namespace App\Tests\Leave\Policy;

use App\Entity\Employee;
use App\Leave\Policy\EntitlementCalculator;
use PHPUnit\Framework\TestCase;

final class EntitlementCalculatorTest extends TestCase
{
    private EntitlementCalculator $calc;

    #[\Override]
    protected function setUp(): void
    {
        $this->calc = new EntitlementCalculator();
    }

    public function testFullTimeFullYear(): void
    {
        self::assertSame(28.0, $this->entitlement(28, 5, '2018-06-01'));
    }

    public function testPartTimeRoundsUpToHalf(): void
    {
        // 28 × 3/5 = 16.8 → 17.0
        self::assertSame(17.0, $this->entitlement(28, 3, '2017-01-01'));
    }

    public function testMidYearJoinerProRata(): void
    {
        // Joined 1 March → 10 full months; 30 × 10/12 = 25.0
        self::assertSame(25.0, $this->entitlement(30, 5, '2025-03-01'));
    }

    public function testJoinerMidMonthLosesThatMonth(): void
    {
        // Joined 2 March → first full month is April → 9 months; 24 × 9/12 = 18.0
        self::assertSame(18.0, $this->entitlement(24, 5, '2025-03-02'));
    }

    public function testLeaverProRata(): void
    {
        // Employed through 30 June → 6 full months; 28 × 6/12 = 14.0
        self::assertSame(14.0, $this->entitlement(28, 5, '2015-01-01', '2025-06-30'));
    }

    public function testPartTimeAndJoinerCompose(): void
    {
        // Joined 1 March (10 months), 3-day week: 30 × 10/12 × 3/5 = 15.0
        self::assertSame(15.0, $this->entitlement(30, 3, '2025-03-01'));
    }

    public function testContractualIsTrustedNotClamped(): void
    {
        // §1 guarantees ≥ statutory; a below-floor value is trusted as given, not rewritten.
        self::assertSame(10.0, $this->entitlement(10, 5, '2018-01-01'));
    }

    private function entitlement(int $contractual, int $wdpw, string $start, ?string $end = null): float
    {
        $employee = new Employee(
            'T',
            new \DateTimeImmutable($start),
            $wdpw,
            'BE',
            $contractual,
            $end ? new \DateTimeImmutable($end) : null,
        );

        return $this->calc->forEmployee($employee, 2025);
    }
}
