<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AuditLog;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<AuditLog>
 */
class AuditLogRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AuditLog::class);
    }

    /**
     * @return AuditLog[]
     */
    public function findByEntityType(string $entityType): array
    {
        return $this->createQueryBuilder('a')
            ->andWhere('a.entityType = :entityType')
            ->setParameter('entityType', $entityType)
            ->orderBy('a.performedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return AuditLog[]
     */
    public function findByEntityTypeAndId(string $entityType, string $entityId): array
    {
        return $this->createQueryBuilder('a')
            ->andWhere('a.entityType = :entityType')
            ->andWhere('a.entityId = :entityId')
            ->setParameter('entityType', $entityType)
            ->setParameter('entityId', $entityId)
            ->orderBy('a.performedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return AuditLog[]
     */
    public function findBySuperAdmin(Uuid $superAdminUserId): array
    {
        return $this->createQueryBuilder('a')
            ->andWhere('a.superAdminUserId = :superAdminUserId')
            ->setParameter('superAdminUserId', $superAdminUserId)
            ->orderBy('a.performedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return AuditLog[]
     */
    public function findByAction(string $action): array
    {
        return $this->createQueryBuilder('a')
            ->andWhere('a.action = :action')
            ->setParameter('action', $action)
            ->orderBy('a.performedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
