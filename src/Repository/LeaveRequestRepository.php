<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Employee;
use App\Entity\LeaveRequest;
use App\Enum\LeaveStatus;
use App\Enum\LeaveType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<LeaveRequest>
 */
class LeaveRequestRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, LeaveRequest::class);
    }

    /**
     * All requests still awaiting a decision, oldest submission first.
     *
     * @return list<LeaveRequest>
     */
    public function findPending(): array
    {
        return $this->createQueryBuilder('r')
            ->andWhere('r.status = :status')
            ->setParameter('status', LeaveStatus::PENDING)
            ->orderBy('r.submittedAt', 'ASC')
            ->addOrderBy('r.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Approved requests of one type for an employee — used for overlap detection
     * (§10) and the §9 sick-during-vacation credit. Reflects requests approved
     * earlier in the same run, since the processor commits per request.
     *
     * @return list<LeaveRequest>
     */
    public function findApprovedByType(Employee $employee, LeaveType $type): array
    {
        return $this->createQueryBuilder('r')
            ->andWhere('r.employee = :employee')
            ->andWhere('r.type = :type')
            ->andWhere('r.status = :approved')
            ->setParameter('employee', $employee)
            ->setParameter('type', $type)
            ->setParameter('approved', LeaveStatus::APPROVED)
            ->getQuery()
            ->getResult();
    }

    /**
     * SICK periods the employee has claimed and not withdrawn — every status except
     * CANCELLED. Used to measure continuous incapacity for the certificate rule: a
     * just-rejected sibling still counts (the days were claimed), so back-to-back
     * short notes are judged as one period rather than each slipping through.
     *
     * @return list<LeaveRequest>
     */
    public function findClaimedSick(Employee $employee): array
    {
        return $this->createQueryBuilder('r')
            ->andWhere('r.employee = :employee')
            ->andWhere('r.type = :sick')
            ->andWhere('r.status != :cancelled')
            ->setParameter('employee', $employee)
            ->setParameter('sick', LeaveType::SICK)
            ->setParameter('cancelled', LeaveStatus::CANCELLED)
            ->getQuery()
            ->getResult();
    }
}
