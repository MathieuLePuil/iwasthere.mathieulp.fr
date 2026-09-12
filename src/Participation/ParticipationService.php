<?php

declare(strict_types=1);

namespace App\Participation;

use App\Entity\Event;
use App\Entity\EventParticipation;
use App\Notification\NotificationDispatcher;
use App\Notification\NotificationType;
use App\Repository\EventParticipationRepository;
use App\Repository\NotificationRepository;
use App\Repository\UserRepository;
use App\Service\EventImageService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Le cycle de vie d'une participation, partagé par le journal, le parcours de
 * complétion et l'admin.
 *
 * Une participation n'est jamais seule en base : des réactions y pointent
 * (cascade SQL), des notifications de tag la citent dans leur JSON, une photo
 * l'accompagne sur le disque, et `event.participant_count` la compte. Tout
 * appelant qui la supprime doit défaire les quatre — d'où ce point unique.
 * Même chose pour les accompagnants : les prévenir obéit à des règles (déjà
 * tagué, déjà participant) qui étaient recopiées trois fois dans le contrôleur.
 */
final class ParticipationService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly NotificationRepository $notifRepo,
        private readonly EventParticipationRepository $participations,
        private readonly UserRepository $users,
        private readonly NotificationDispatcher $notifier,
        private readonly UrlGeneratorInterface $urls,
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

    /**
     * Les ids des amis in-app d'une liste « Avec qui » — à relever avant de la
     * remplacer, pour ne prévenir que les nouveaux venus.
     *
     * @return list<string>
     */
    public function appFriendIds(EventParticipation $participation): array
    {
        $ids = [];
        foreach ($participation->getFriends() as $f) {
            if (($f['type'] ?? '') === 'app' && isset($f['userId'])) {
                $ids[] = (string) $f['userId'];
            }
        }

        return $ids;
    }

    /**
     * Prévient les amis in-app que la participation vient de taguer, sauf ceux
     * qui l'étaient déjà ($previousAppFriendIds) et ceux qui ont leur propre
     * participation à l'événement — on n'invite pas à ce qu'on vit déjà.
     * Chaque notification porte les ids nécessaires à accepter ou refuser.
     *
     * @param list<string> $previousAppFriendIds
     */
    public function notifyNewTags(EventParticipation $participation, array $previousAppFriendIds = []): void
    {
        $me = $participation->getUser();
        $event = $participation->getEvent();
        $name = self::name($event);

        $newIds = array_values(array_diff($this->appFriendIds($participation), $previousAppFriendIds));
        foreach ($this->users->findByIds($newIds) as $taggedUser) {
            if ($this->participations->findByUserAndEvent($taggedUser, $event) !== null) {
                continue;
            }
            $this->notifier->dispatch(
                $taggedUser,
                NotificationType::FriendTaggedInEvent,
                $me->getDisplayName() . ' t\'a ajouté à un événement',
                $name,
                $this->urls->generate('app_notifications'),
                [
                    'eventId'         => (string) $event->getId(),
                    'participationId' => (string) $participation->getId(),
                    'eventName'       => $name,
                ],
            );
        }
    }

    /**
     * Rattache mutuellement cette participation à celles, sur le même événement,
     * qui taguent déjà son utilisateur dans leur « Avec qui ». Un ami qui invite
     * (ou est invité) reste ainsi associé à l'événement de bout en bout.
     */
    public function linkWithTaggers(EventParticipation $mine): void
    {
        $userId = (string) $mine->getUser()->getId();

        foreach ($this->participations->findByEvent($mine->getEvent()) as $other) {
            if ($other === $mine || $other->getUser() === $mine->getUser()) {
                continue;
            }
            foreach ($other->getFriends() ?? [] as $f) {
                if (($f['type'] ?? '') === 'app' && ($f['userId'] ?? '') === $userId) {
                    $this->linkCompanions($mine, $other);
                    break;
                }
            }
        }
    }

    /** Inscrit chacun comme accompagnant de l'autre — la relation est symétrique. */
    public function linkCompanions(EventParticipation $a, EventParticipation $b): void
    {
        foreach ([[$a, $b], [$b, $a]] as [$participation, $companion]) {
            $user = $companion->getUser();
            $friends = $participation->getFriends();

            foreach ($friends as $f) {
                if (($f['type'] ?? '') === 'app' && ($f['userId'] ?? '') === (string) $user->getId()) {
                    continue 2;
                }
            }

            $friends[] = [
                'type' => 'app',
                'userId' => (string) $user->getId(),
                'username' => $user->getUsername(),
                'displayName' => $user->getDisplayName(),
            ];
            $participation->setFriends($friends);
        }
    }

    /** Le nom sous lequel un événement est cité dans une notification. */
    public static function name(Event $event): string
    {
        return $event->getArtistName() ?? $event->getTournamentName() ?? $event->getTeams() ?? 'Événement';
    }
}
