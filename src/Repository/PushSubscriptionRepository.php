<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\PushSubscription;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ParameterType;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PushSubscription>
 */
class PushSubscriptionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PushSubscription::class);
    }

    /** @return PushSubscription[] */
    public function findByUser(User $user): array
    {
        return $this->createQueryBuilder('ps')
            ->where('ps.user = :user')
            ->setParameter('user', $user->getId()->toBinary(), ParameterType::BINARY)
            ->getQuery()
            ->getResult();
    }

    public function findByEndpoint(string $endpoint): ?PushSubscription
    {
        return $this->findOneBy(['endpoint' => $endpoint]);
    }
}
