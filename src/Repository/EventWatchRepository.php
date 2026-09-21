<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\EventWatch;
use App\Entity\TmEvent;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ParameterType;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<EventWatch>
 */
class EventWatchRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EventWatch::class);
    }

    /**
     * Les veilles d'un utilisateur, événement et fenêtres chargés, ouverture
     * la plus proche d'abord ; les inactives (annulées) en fin de liste.
     *
     * @return list<EventWatch>
     */
    public function findForUser(User $user): array
    {
        /** @var list<EventWatch> $watches */
        $watches = $this->createQueryBuilder('ew')
            ->addSelect('e', 'w')
            ->join('ew.event', 'e')
            ->leftJoin('e.saleWindows', 'w')
            ->where('ew.user = :user')
            ->setParameter('user', $user->getId()->toBinary(), ParameterType::BINARY)
            ->orderBy('ew.active', 'DESC')
            ->addOrderBy('w.startsAtUtc', 'ASC')
            ->getQuery()
            ->getResult();

        return $watches;
    }

    public function findOneForUserAndEvent(User $user, TmEvent $event): ?EventWatch
    {
        return $this->findOneBy(['user' => $user, 'event' => $event]);
    }

    /**
     * Toutes les veilles actives, avec ce qu'il faut pour les replanifier.
     *
     * @return list<EventWatch>
     */
    public function findActive(): array
    {
        /** @var list<EventWatch> $watches */
        $watches = $this->createQueryBuilder('ew')
            ->addSelect('e', 'w', 'u')
            ->join('ew.event', 'e')
            ->join('ew.user', 'u')
            ->leftJoin('e.saleWindows', 'w')
            ->where('ew.active = true')
            ->getQuery()
            ->getResult();

        return $watches;
    }

    /**
     * Les segments suivis par au moins une veille active — ce que la
     * synchronisation doit garder du flux, en plus de Music.
     *
     * @return list<string>
     */
    public function findFollowedSegments(): array
    {
        /** @var list<string> $segments */
        $segments = $this->getEntityManager()->getConnection()->fetchFirstColumn(
            'SELECT DISTINCT e.segment FROM event_watch ew
             JOIN tm_event e ON e.event_id = ew.event_id
             WHERE ew.active = 1 AND e.segment IS NOT NULL',
        );

        return $segments;
    }

    /**
     * L'état connu des événements suivis, avant synchronisation : c'est en le
     * comparant au flux qu'on détecte annulations, reports et ouvertures
     * déplacées.
     *
     * @return array<string, array{status: string, onsale: ?string}> event_id → état (onsale en « Y-m-d H:i:s » UTC)
     */
    public function snapshotWatchedEvents(): array
    {
        /** @var list<array{event_id: string, status: string, onsale: ?string}> $rows */
        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            "SELECT e.event_id, e.status, w.starts_at_utc AS onsale
             FROM tm_event e
             JOIN event_watch ew ON ew.event_id = e.event_id AND ew.active = 1
             LEFT JOIN tm_sale_window w ON w.event_id = e.event_id AND w.type = 'public'
             GROUP BY e.event_id, e.status, w.starts_at_utc",
        );

        $snapshot = [];
        foreach ($rows as $row) {
            $snapshot[$row['event_id']] = ['status' => $row['status'], 'onsale' => $row['onsale']];
        }

        return $snapshot;
    }

    /**
     * Les veilles actives d'un événement.
     *
     * @return list<EventWatch>
     */
    public function findActiveForEvent(string $eventId): array
    {
        /** @var list<EventWatch> $watches */
        $watches = $this->createQueryBuilder('ew')
            ->addSelect('u')
            ->join('ew.user', 'u')
            ->where('ew.event = :event')
            ->andWhere('ew.active = true')
            ->setParameter('event', $eventId)
            ->getQuery()
            ->getResult();

        return $watches;
    }
}
