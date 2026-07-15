<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Notification;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ParameterType;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Notification>
 */
class NotificationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Notification::class);
    }

    /**
     * @return Notification[]
     */
    public function findByRecipient(User $recipient): array
    {
        return $this->createQueryBuilder('n')
            ->andWhere('n.recipient = :recipient')
            ->setParameter('recipient', $recipient->getId()->toBinary(), ParameterType::BINARY)
            ->orderBy('n.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return Notification[]
     */
    public function findUnreadByRecipient(User $recipient): array
    {
        return $this->createQueryBuilder('n')
            ->andWhere('n.recipient = :recipient')
            ->andWhere('n.isRead = :isRead')
            ->setParameter('recipient', $recipient->getId()->toBinary(), ParameterType::BINARY)
            ->setParameter('isRead', false)
            ->orderBy('n.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function countUnreadByRecipient(User $recipient): int
    {
        return (int) $this->createQueryBuilder('n')
            ->select('COUNT(n.id)')
            ->andWhere('n.recipient = :recipient')
            ->andWhere('n.isRead = :isRead')
            ->setParameter('recipient', $recipient->getId()->toBinary(), ParameterType::BINARY)
            ->setParameter('isRead', false)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countUnread(User $user): int
    {
        return (int) $this->createQueryBuilder('n')
            ->select('COUNT(n.id)')
            ->where('n.recipient = :user')
            ->andWhere('n.isRead = false')
            ->setParameter('user', $user->getId()->toBinary(), ParameterType::BINARY)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function findFriendRequestNotification(User $recipient, string $friendId): ?Notification
    {
        $results = $this->createQueryBuilder('n')
            ->where('n.recipient = :recipient')
            ->andWhere('n.type = :type')
            ->setParameter('recipient', $recipient->getId()->toBinary(), ParameterType::BINARY)
            ->setParameter('type', 'friend_request')
            ->getQuery()
            ->getResult();

        foreach ($results as $n) {
            $data = $n->getData();
            if (isset($data['friendId']) && $data['friendId'] === $friendId) {
                return $n;
            }
        }

        return null;
    }

    public function findEventTagNotification(User $recipient, string $participationId): ?Notification
    {
        $results = $this->createQueryBuilder('n')
            ->where('n.recipient = :recipient')
            ->andWhere('n.type = :type')
            ->setParameter('recipient', $recipient->getId()->toBinary(), ParameterType::BINARY)
            ->setParameter('type', 'friend_tagged_in_event')
            ->getQuery()
            ->getResult();

        foreach ($results as $n) {
            $data = $n->getData();
            if (isset($data['participationId']) && $data['participationId'] === $participationId) {
                return $n;
            }
        }

        return null;
    }

    /**
     * La question « vous y allez ensemble ? » posée à l'autre pour le même
     * événement. C'est elle qui porte sa réponse : l'accord n'est conclu que
     * lorsque les deux questions portent « oui ».
     */
    public function findTogetherQuestion(User $recipient, string $eventId, string $otherUserId): ?Notification
    {
        $results = $this->createQueryBuilder('n')
            ->where('n.recipient = :recipient')
            ->andWhere('n.type = :type')
            ->setParameter('recipient', $recipient->getId()->toBinary(), ParameterType::BINARY)
            ->setParameter('type', 'friend_same_event')
            ->getQuery()
            ->getResult();

        foreach ($results as $n) {
            $data = $n->getData() ?? [];
            if (($data['eventId'] ?? null) === $eventId && ($data['otherUserId'] ?? null) === $otherUserId) {
                return $n;
            }
        }

        return null;
    }

    /**
     * Une notification de ce type porte-t-elle déjà cette clé ? Garde-fou des
     * rappels : le cron tourne chaque minute et rejouerait sinon l'envoi.
     */
    public function existsForDedupeKey(User $recipient, string $type, string $dedupeKey): bool
    {
        $results = $this->createQueryBuilder('n')
            ->select('n.data')
            ->where('n.recipient = :recipient')
            ->andWhere('n.type = :type')
            ->setParameter('recipient', $recipient->getId()->toBinary(), ParameterType::BINARY)
            ->setParameter('type', $type)
            ->getQuery()
            ->getArrayResult();

        foreach ($results as $row) {
            if (($row['data']['dedupeKey'] ?? null) === $dedupeKey) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return Notification[]
     */
    public function findForUser(User $user, int $limit = 20): array
    {
        return $this->createQueryBuilder('n')
            ->where('n.recipient = :user')
            ->setParameter('user', $user->getId()->toBinary(), ParameterType::BINARY)
            ->orderBy('n.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
