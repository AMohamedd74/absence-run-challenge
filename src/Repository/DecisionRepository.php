<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Decision;
use App\Entity\Employee;
use App\Entity\LeaveRequest;
use App\Enum\LeaveStatus;
use App\Enum\LeaveType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Decision>
 */
class DecisionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Decision::class);
    }

    public function findByKey(string $idempotencyKey): ?Decision
    {
        return $this->findOneBy(['idempotencyKey' => $idempotencyKey]);
    }

    /** Whether a request has already been reversed (has a CANCELLED decision). */
    public function hasReversal(Decision|LeaveRequest $requestOrDecision): bool
    {
        $request = $requestOrDecision instanceof Decision ? $requestOrDecision->getRequest() : $requestOrDecision;

        return null !== $this->findOneBy(['request' => $request, 'status' => LeaveStatus::CANCELLED]);
    }

    /**
     * Approved decisions whose request has since been CANCELLED and that have not
     * yet been reversed — the §12 "previously approved, then cancelled" set.
     * Zero-impact approvals (UNPAID/SPECIAL) are included so HR still receives a
     * cancellation, even though there are no days to credit back.
     *
     * @return list<Decision>
     */
    public function findApprovedAwaitingReversal(): array
    {
        return $this->createQueryBuilder('d')
            ->join('d.request', 'r')
            ->andWhere('d.status = :approved')
            ->andWhere('r.status = :cancelled')
            ->andWhere(
                'NOT EXISTS (SELECT d2.id FROM '.Decision::class.' d2 '
                .'WHERE d2.request = r AND d2.status = :cancelled)'
            )
            ->setParameter('approved', LeaveStatus::APPROVED)
            ->setParameter('cancelled', LeaveStatus::CANCELLED)
            ->getQuery()
            ->getResult();
    }

    /**
     * Active §9 sick credits for an employee — approved SICK decisions that returned
     * days to the balance and have not been reversed. Used to revoke a credit whose
     * underlying vacation is being cancelled.
     *
     * @return list<Decision>
     */
    public function findActiveSickCredits(Employee $employee): array
    {
        return $this->createQueryBuilder('d')
            ->join('d.request', 'r')
            ->andWhere('r.employee = :employee')
            ->andWhere('d.type = :sick')
            ->andWhere('d.status = :approved')
            ->andWhere('d.balanceDelta < 0')
            ->andWhere(
                'NOT EXISTS (SELECT d2.id FROM '.Decision::class.' d2 '
                .'WHERE d2.request = r AND d2.status = :cancelled)'
            )
            ->setParameter('employee', $employee)
            ->setParameter('sick', LeaveType::SICK)
            ->setParameter('approved', LeaveStatus::APPROVED)
            ->setParameter('cancelled', LeaveStatus::CANCELLED)
            ->getQuery()
            ->getResult();
    }
}
