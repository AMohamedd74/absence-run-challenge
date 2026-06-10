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
}
