<?php

declare(strict_types=1);

namespace App\Repository;

use App\Doctrine\UtcDateTimeImmutableType;
use App\Entity\EventWatch;
use App\Entity\User;
use App\Entity\WatchNotification;
use App\Ticketmaster\WatchNotificationStatus;
use App\Ticketmaster\WatchNotificationType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ParameterType;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<WatchNotification>
 */
class WatchNotificationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WatchNotification::class);
    }

    /**
     * Toutes les échéances d'une veille, tous statuts — la replanification a
     * besoin des envoyées comme des annulées.
     *
     * @return list<WatchNotification>
     */
    public function findForWatch(EventWatch $watch): array
    {
        /** @var list<WatchNotification> $rows */
        $rows = $this->createQueryBuilder('n')
            ->where('n.watch = :watch')
            ->setParameter('watch', $watch->getId()->toBinary(), ParameterType::BINARY)
            ->orderBy('n.scheduledFor', 'ASC')
            ->getQuery()
            ->getResult();

        return $rows;
    }

    /**
     * Les échéances à venir de plusieurs veilles — pour la liste (F4).
     *
     * @param list<EventWatch> $watches
     *
     * @return array<string, list<WatchNotification>> id de veille → échéances, par heure
     */
    public function findPendingForWatches(array $watches): array
    {
        if ($watches === []) {
            return [];
        }

        /** @var list<WatchNotification> $rows */
        $rows = $this->createQueryBuilder('n')
            ->where('n.watch IN (:watches)')
            ->andWhere('n.status = :pending')
            ->setParameter('watches', array_map(static fn (EventWatch $w) => $w->getId()->toBinary(), $watches))
            ->setParameter('pending', WatchNotificationStatus::Pending)
            ->orderBy('n.scheduledFor', 'ASC')
            ->getQuery()
            ->getResult();

        $byWatch = [];
        foreach ($rows as $row) {
            $byWatch[(string) $row->getWatch()->getId()][] = $row;
        }

        return $byWatch;
    }

    /**
     * Ce qui doit partir : en attente, heure atteinte, veille encore active.
     * Chargé avec tout ce qu'il faut pour composer le message.
     *
     * @return list<WatchNotification>
     */
    public function findDue(\DateTimeImmutable $now): array
    {
        /** @var list<WatchNotification> $rows */
        $rows = $this->dueQueryBuilder()
            ->andWhere('n.scheduledFor <= :now')
            ->setParameter('now', $now, UtcDateTimeImmutableType::NAME)
            ->getQuery()
            ->getResult();

        return $rows;
    }

    /**
     * Les échéances du même type, pour le même utilisateur, qui tombent juste
     * après une échéance due : on les prend dans le même push plutôt que d'en
     * envoyer un second deux minutes plus tard.
     *
     * @return list<WatchNotification>
     */
    public function findPendingSoon(User $user, WatchNotificationType $type, \DateTimeImmutable $after, \DateTimeImmutable $until): array
    {
        /** @var list<WatchNotification> $rows */
        $rows = $this->dueQueryBuilder()
            ->andWhere('u.id = :user')
            ->andWhere('n.type = :type')
            ->andWhere('n.scheduledFor > :after')
            ->andWhere('n.scheduledFor <= :until')
            ->setParameter('user', $user->getId()->toBinary(), ParameterType::BINARY)
            ->setParameter('type', $type)
            ->setParameter('after', $after, UtcDateTimeImmutableType::NAME)
            ->setParameter('until', $until, UtcDateTimeImmutableType::NAME)
            ->getQuery()
            ->getResult();

        return $rows;
    }

    private function dueQueryBuilder(): \Doctrine\ORM\QueryBuilder
    {
        return $this->createQueryBuilder('n')
            ->addSelect('ew', 'u', 'e', 'w')
            ->join('n.watch', 'ew')
            ->join('ew.user', 'u')
            ->join('ew.event', 'e')
            ->leftJoin('n.saleWindow', 'w')
            ->where('n.status = :pending')
            // Une annulation désactive la veille ; la notification d'annulation doit quand même partir
            ->andWhere('ew.active = true OR n.type = :cancelledType')
            ->setParameter('pending', WatchNotificationStatus::Pending)
            ->setParameter('cancelledType', WatchNotificationType::Cancelled)
            ->orderBy('n.scheduledFor', 'ASC');
    }

    /**
     * Combien de pushes distincts cet utilisateur a reçus depuis `$since` —
     * le plafond journalier compte les envois, pas les événements qu'ils citent.
     */
    public function countBatchesSince(User $user, \DateTimeImmutable $since): int
    {
        return (int) $this->createQueryBuilder('n')
            ->select('COUNT(DISTINCT n.pushBatch)')
            ->join('n.watch', 'ew')
            ->where('ew.user = :user')
            ->andWhere('n.sentAt >= :since')
            ->andWhere('n.pushBatch IS NOT NULL')
            ->andWhere('n.status IN (:statuses)')
            ->setParameter('user', $user->getId()->toBinary(), ParameterType::BINARY)
            ->setParameter('since', $since, UtcDateTimeImmutableType::NAME)
            ->setParameter('statuses', [WatchNotificationStatus::Queued, WatchNotificationStatus::Sent, WatchNotificationStatus::Logged])
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Les échéances d'un même push, avec tout ce qu'il faut pour le composer.
     *
     * @return list<WatchNotification>
     */
    public function findByBatch(Uuid $batch): array
    {
        /** @var list<WatchNotification> $rows */
        $rows = $this->createQueryBuilder('n')
            ->addSelect('ew', 'u', 'e', 'w')
            ->join('n.watch', 'ew')
            ->join('ew.user', 'u')
            ->join('ew.event', 'e')
            ->leftJoin('n.saleWindow', 'w')
            ->where('n.pushBatch = :batch')
            ->setParameter('batch', $batch->toBinary(), ParameterType::BINARY)
            ->orderBy('n.scheduledFor', 'ASC')
            ->getQuery()
            ->getResult();

        return $rows;
    }

    /** Purge : les échéances parties depuis plus de `$before`. */
    public function purgeSent(\DateTimeImmutable $before): int
    {
        return (int) $this->createQueryBuilder('n')
            ->delete()
            ->where('n.sentAt IS NOT NULL')
            ->andWhere('n.sentAt < :before')
            ->setParameter('before', $before, UtcDateTimeImmutableType::NAME)
            ->getQuery()
            ->execute();
    }
}
