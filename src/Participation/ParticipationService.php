<?php

declare(strict_types=1);

namespace App\Participation;

use App\Entity\EventParticipation;
use App\Repository\NotificationRepository;
use App\Service\EventImageService;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Le cycle de vie d'une participation, partagé par le journal et l'admin.
 *
 * Une participation n'est jamais seule en base : des réactions y pointent
 * (cascade SQL), des notifications de tag la citent dans leur JSON, une photo
 * l'accompagne sur le disque, et `event.participant_count` la compte. Tout
 * appelant qui la supprime doit défaire les quatre — d'où ce point unique.
 */
final class ParticipationService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly NotificationRepository $notifRepo,
        private readonly EventImageService $images,
    ) {}

    /** Retire la participation et tout ce qui la référence. Ne flush pas. */
    public function remove(EventParticipation $participation): void
    {
        $event = $participation->getEvent();

        $this->notifRepo->deleteReferencingParticipation((string) $participation->getId());
        $this->images->delete($participation);

        $event->setParticipantCount(max(0, $event->getParticipantCount() - 1));
        $this->em->remove($participation);
    }
}
