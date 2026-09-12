<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\PushSubscription;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<PushSubscription> */
class PushSubscriptionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PushSubscription::class);
    }

    public function findOneByEndpoint(string $endpoint): ?PushSubscription
    {
        return $this->findOneBy(['endpoint' => $endpoint]);
    }

    /** @return PushSubscription[] */
    public function findForUser(User $user): array
    {
        return $this->createQueryBuilder('s')
            ->where('s.user = :user')
            ->setParameter('user', $user->getId()->toBinary(), ParameterType::BINARY)
            ->getQuery()
            ->getResult();
    }

    /** @return PushSubscription[] */
    public function findForUserId(string $userId): array
    {
        return $this->createQueryBuilder('s')
            ->where('s.user = :user')
            ->setParameter('user', \Symfony\Component\Uid\Uuid::fromString($userId)->toBinary(), ParameterType::BINARY)
            ->getQuery()
            ->getResult();
    }

    /** Efface les abonnements que le serveur de push a déclarés expirés (410/404). */
    public function deleteByEndpoints(array $endpoints): void
    {
        if ($endpoints === []) {
            return;
        }
        $this->createQueryBuilder('s')
            ->delete()
            ->where('s.endpoint IN (:endpoints)')
            ->setParameter('endpoints', $endpoints, ArrayParameterType::STRING)
            ->getQuery()
            ->execute();
    }
}
