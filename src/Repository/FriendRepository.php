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
    /**
     * Les ids des amis confirmés, indexés pour un test d'appartenance en O(1).
     *
     * @return array<string, true>
     */
    public function findConfirmedFriendIds(User $user): array
    {
        $ids = [];
        foreach ($this->findConfirmedFriends($user) as $rel) {
            $other = $rel->getOwner()->getId()->equals($user->getId()) ? $rel->getFriendUser() : $rel->getOwner();
            if ($other !== null) {
                $ids[(string) $other->getId()] = true;
            }
        }

        return $ids;
    }

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
