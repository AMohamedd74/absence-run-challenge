<?php

declare(strict_types=1);

namespace App\Tests\Leave\Decision;

use App\Entity\Employee;
use App\Entity\LeaveBalance;
use App\Entity\LeaveRequest;
use App\Enum\LeaveStatus;
use App\Enum\LeaveType;
use App\Tests\AbsenceRunTestCase;

final class SickLeaveTest extends AbsenceRunTestCase
{
    public function testShortSickWithoutCertificateIsRecorded(): void
    {
        $employee = new Employee('Sniffles', new \DateTimeImmutable('2018-01-01'), 5, 'BE', 28);
        $balance = new LeaveBalance($employee, 2025, 0.0, null, 10.0);
        $sick = $this->sick($employee, '2025-05-19', '2025-05-21', certificate: false); // 3 calendar days
        $this->persist($employee, $balance, $sick);

        $this->processor()->processPending(new \DateTimeImmutable('2025-06-01'));

        self::assertSame(LeaveStatus::APPROVED, $sick->getStatus());
        self::assertSame(10.0, $balance->getUsedDays(), 'sick never touches the vacation balance');
    }

    public function testLongSickWithoutCertificateIsRejected(): void
    {
        $employee = new Employee('Fluish', new \DateTimeImmutable('2018-01-01'), 5, 'BE', 28);
        $balance = new LeaveBalance($employee, 2025, 0.0, null, 10.0);
        $sick = $this->sick($employee, '2025-05-19', '2025-05-23', certificate: false); // 5 calendar days
        $this->persist($employee, $balance, $sick);

        $this->processor()->processPending(new \DateTimeImmutable('2025-06-01'));

        self::assertSame(LeaveStatus::REJECTED, $sick->getStatus());
        self::assertStringContainsString('certificate', (string) $sick->getDecisionReason());
        self::assertSame(10.0, $balance->getUsedDays());
    }

    public function testBackToBackShortSickNeedsACertificate(): void
    {
        // Two adjacent 3-day notes (Mon–Wed then Thu–Fri) are one 5-day continuous
        // incapacity, so the certificate rule applies — neither slips through.
        $employee = new Employee('Serial', new \DateTimeImmutable('2018-01-01'), 5, 'BE', 28);
        $balance = new LeaveBalance($employee, 2025, 0.0, null, 0.0);
        $first = $this->sick($employee, '2025-05-19', '2025-05-21', certificate: false);  // Mon–Wed
        $second = $this->sick($employee, '2025-05-22', '2025-05-23', certificate: false); // Thu–Fri (adjacent)
        $this->persist($employee, $balance, $first, $second);

        $this->processor()->processPending(new \DateTimeImmutable('2025-06-01'));

        self::assertSame(LeaveStatus::REJECTED, $first->getStatus(), 'part of a 5-day continuous incapacity');
        self::assertSame(LeaveStatus::REJECTED, $second->getStatus());
    }

    public function testSeparateShortSickPeriodsAreEachAccepted(): void
    {
        // Two 3-day notes weeks apart are distinct incapacities — each is within the
        // threshold, so a certificate isn't required.
        $employee = new Employee('Occasional', new \DateTimeImmutable('2018-01-01'), 5, 'BE', 28);
        $balance = new LeaveBalance($employee, 2025, 0.0, null, 0.0);
        $may = $this->sick($employee, '2025-05-19', '2025-05-21', certificate: false);
        $june = $this->sick($employee, '2025-06-16', '2025-06-18', certificate: false);
        $this->persist($employee, $balance, $may, $june);

        $this->processor()->processPending(new \DateTimeImmutable('2025-07-01'));

        self::assertSame(LeaveStatus::APPROVED, $may->getStatus());
        self::assertSame(LeaveStatus::APPROVED, $june->getStatus());
    }

    public function testSickDuringApprovedVacationCreditsBackOverlap(): void
    {
        $employee = new Employee('Dilan', new \DateTimeImmutable('2015-01-01'), 5, 'BY', 30);
        $balance = new LeaveBalance($employee, 2025, 0.0, null, 10.0);

        // An already-approved March vacation (the 10 used days).
        $vacation = new LeaveRequest($employee, LeaveType::VACATION,
            new \DateTimeImmutable('2025-03-17'), new \DateTimeImmutable('2025-03-28'),
            new \DateTimeImmutable('2025-02-01'));
        $vacation->markDecided(LeaveStatus::APPROVED, new \DateTimeImmutable('2025-02-15'), 'within balance');

        // A sick note with a certificate covering 3 of those working days.
        $sick = $this->sick($employee, '2025-03-24', '2025-03-26', certificate: true);
        $this->persist($employee, $balance, $vacation, $sick);

        $this->processor()->processPending(new \DateTimeImmutable('2025-04-15'));

        self::assertSame(LeaveStatus::APPROVED, $sick->getStatus());
        self::assertSame(7.0, $balance->getUsedDays(), '3 overlapping working days credited back');
        self::assertSame(-3.0, $this->hrApi->calls[0]['decision']['days']);
    }

    public function testSickWithCertificateButNoOverlapHasNoImpact(): void
    {
        $employee = new Employee('Healthy', new \DateTimeImmutable('2018-01-01'), 5, 'BE', 28);
        $balance = new LeaveBalance($employee, 2025, 0.0, null, 4.0);
        $sick = $this->sick($employee, '2025-05-19', '2025-05-28', certificate: true); // long, but certified
        $this->persist($employee, $balance, $sick);

        $this->processor()->processPending(new \DateTimeImmutable('2025-06-01'));

        self::assertSame(LeaveStatus::APPROVED, $sick->getStatus());
        self::assertSame(4.0, $balance->getUsedDays());
    }

    private function sick(Employee $employee, string $start, string $end, bool $certificate): LeaveRequest
    {
        $request = new LeaveRequest(
            $employee,
            LeaveType::SICK,
            new \DateTimeImmutable($start),
            new \DateTimeImmutable($end),
            new \DateTimeImmutable('2025-04-01'),
        );
        $request->setMedicalCertificate($certificate);

        return $request;
    }
}
