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
        return $this->findOneReferencing($recipient, 'friend_request', 'friendId', $friendId);
    }

    public function findEventTagNotification(User $recipient, string $participationId): ?Notification
    {
        return $this->findOneReferencing($recipient, 'friend_tagged_in_event', 'participationId', $participationId);
    }

    public function findTogetherQuestion(User $recipient, string $eventId, string $otherUserId): ?Notification
    {
        return $this->createQueryBuilder('n')
            ->where('n.recipient = :recipient')
            ->andWhere('n.type = :type')
            ->andWhere("JSON_VALUE(n.data, '$.eventId') = :eventId")
            ->andWhere("JSON_VALUE(n.data, '$.otherUserId') = :otherUserId")
            ->setParameter('recipient', $recipient->getId()->toBinary(), ParameterType::BINARY)
            ->setParameter('type', 'friend_same_event')
            ->setParameter('eventId', $eventId)
            ->setParameter('otherUserId', $otherUserId)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function existsForDedupeKey(User $recipient, string $type, string $dedupeKey): bool
    {
        return (int) $this->createQueryBuilder('n')
            ->select('COUNT(n.id)')
            ->where('n.recipient = :recipient')
            ->andWhere('n.type = :type')
            ->andWhere('n.dedupeKey = :key')
            ->setParameter('recipient', $recipient->getId()->toBinary(), ParameterType::BINARY)
            ->setParameter('type', $type)
            ->setParameter('key', $dedupeKey)
            ->getQuery()
            ->getSingleScalarResult() > 0;
    }

    /** La notification d'un type dont `data.$field` vaut $value — en SQL, pas en balayant le JSON en PHP. */
    private function findOneReferencing(User $recipient, string $type, string $field, string $value): ?Notification
    {
        return $this->createQueryBuilder('n')
            ->where('n.recipient = :recipient')
            ->andWhere('n.type = :type')
            ->andWhere(sprintf("JSON_VALUE(n.data, '$.%s') = :value", $field))
            ->setParameter('recipient', $recipient->getId()->toBinary(), ParameterType::BINARY)
            ->setParameter('type', $type)
            ->setParameter('value', $value)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /** Efface tout le fil de l'utilisateur, en une requête. */
    public function deleteAllForUser(User $user): int
    {
        return $this->createQueryBuilder('n')
            ->delete()
            ->where('n.recipient = :user')
            ->setParameter('user', $user->getId()->toBinary(), ParameterType::BINARY)
            ->getQuery()
            ->execute();
    }

    /**
     * Purge les notifications lues plus vieilles que $olderThan. Le fil n'est pas
     * une archive : sans purge, chaque recherche par destinataire grossissait avec
     * l'historique. Les clés de dédoublonnage journalières (day:AAAA-MM-JJ) sont
     * périmées bien avant.
     */
    public function purgeRead(\DateTimeImmutable $olderThan): int
    {
        return $this->createQueryBuilder('n')
            ->delete()
            ->where('n.isRead = true')
            ->andWhere('n.createdAt < :before')
            ->setParameter('before', $olderThan)
            ->getQuery()
            ->execute();
    }

    /**
     * Efface les notifications qui citent cette participation (les invitations
     * « X t'a ajouté à un événement ») : sans leur participation, leurs boutons
     * accepter/refuser n'auraient plus d'objet.
     */
    public function deleteReferencingParticipation(string $participationId): void
    {
        $this->getEntityManager()->createQuery(
            'DELETE FROM App\Entity\Notification n WHERE n.data LIKE :ref'
        )
            ->setParameter('ref', '%"participationId":"' . $participationId . '"%')
            ->execute();
    }

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
