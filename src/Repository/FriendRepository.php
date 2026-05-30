<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Friend;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ParameterType;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Friend>
 */
class FriendRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Friend::class);
    }

    /**
     * @return Friend[]
     */
    public function findByOwner(User $owner): array
    {
        return $this->createQueryBuilder('f')
            ->andWhere('f.owner = :owner')
            ->setParameter('owner', $owner->getId()->toBinary(), ParameterType::BINARY)
            ->orderBy('f.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return Friend[]
     */
    public function findConfirmedByOwner(User $owner): array
    {
        return $this->createQueryBuilder('f')
            ->andWhere('f.owner = :owner')
            ->andWhere('f.status = :status')
            ->setParameter('owner', $owner->getId()->toBinary(), ParameterType::BINARY)
            ->setParameter('status', 'confirmed')
            ->orderBy('f.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return Friend[]
     */
    public function findPendingRequestsForUser(User $user): array
    {
        return $this->createQueryBuilder('f')
            ->andWhere('f.friendUser = :user')
            ->andWhere('f.status = :status')
            ->setParameter('user', $user->getId()->toBinary(), ParameterType::BINARY)
            ->setParameter('status', 'pending')
            ->orderBy('f.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function findOneByOwnerAndFriendUser(User $owner, User $friendUser): ?Friend
    {
        return $this->createQueryBuilder('f')
            ->where('f.owner = :owner')
            ->andWhere('f.friendUser = :friend')
            ->setParameter('owner', $owner->getId()->toBinary(), ParameterType::BINARY)
            ->setParameter('friend', $friendUser->getId()->toBinary(), ParameterType::BINARY)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @return Friend[]
     */
    public function findConfirmedFriends(User $user): array
    {
        return $this->createQueryBuilder('f')
            ->where('(f.owner = :user OR f.friendUser = :user)')
            ->andWhere('f.status = :status')
            ->andWhere('f.friendType = :type')
            ->setParameter('user', $user->getId()->toBinary(), ParameterType::BINARY)
            ->setParameter('status', 'confirmed')
            ->setParameter('type', 'inApp')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return Friend[]
     */
    public function findPendingReceived(User $user): array
    {
        return $this->createQueryBuilder('f')
            ->where('f.friendUser = :user')
            ->andWhere('f.status = :status')
            ->setParameter('user', $user->getId()->toBinary(), ParameterType::BINARY)
            ->setParameter('status', 'pending')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return Friend[]
     */
    public function findPendingSent(User $user): array
    {
        return $this->createQueryBuilder('f')
            ->where('f.owner = :user')
            ->andWhere('f.status = :status')
            ->setParameter('user', $user->getId()->toBinary(), ParameterType::BINARY)
            ->setParameter('status', 'pending')
            ->getQuery()
            ->getResult();
    }

    public function findRelationship(User $a, User $b): ?Friend
    {
        return $this->createQueryBuilder('f')
            ->where('(f.owner = :a AND f.friendUser = :b) OR (f.owner = :b AND f.friendUser = :a)')
            ->setParameter('a', $a->getId()->toBinary(), ParameterType::BINARY)
            ->setParameter('b', $b->getId()->toBinary(), ParameterType::BINARY)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function areFriends(User $a, User $b): bool
    {
        $rel = $this->findRelationship($a, $b);
        return $rel !== null && $rel->getStatus() === 'confirmed';
    }
}
