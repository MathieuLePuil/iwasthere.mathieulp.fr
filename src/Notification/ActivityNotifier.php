<?php

declare(strict_types=1);

namespace App\Notification;

use App\Entity\Event;
use App\Entity\EventParticipation;
use App\Entity\User;
use App\Repository\EventParticipationRepository;
use App\Repository\FriendRepository;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Prévient les amis de ce que quelqu'un vient d'ajouter — le pendant poussé du
 * feed d'activité.
 *
 * Deux messages possibles, jamais les deux : si l'ami va au même événement, il
 * reçoit le message spécifique (« sera aussi à ») plutôt que l'annonce générique,
 * qui est plus intéressant et évite le doublon.
 *
 * Mêmes règles de confidentialité que le feed : amis in-app confirmés,
 * participations privées exclues. Les amis tagués sont exclus aussi — ils ont
 * déjà leur notification de tag.
 */
final class ActivityNotifier
{
    public function __construct(
        private readonly FriendRepository $friendRepo,
        private readonly EventParticipationRepository $participationRepo,
        private readonly NotificationDispatcher $notifier,
        private readonly UrlGeneratorInterface $urls,
    ) {}

    /** Un ami vient d'ajouter un événement à son journal. */
    public function announceParticipation(EventParticipation $participation): void
    {
        if ($participation->getVisibility() === 'private') {
            return;
        }

        $author = $participation->getUser();
        $event = $participation->getEvent();
        $name = $this->eventName($event);
        $upcoming = $event->getDate() >= new \DateTimeImmutable('today');

        foreach ($this->audience($participation) as $friend) {
            $theirs = $this->participationRepo->findByUserAndEvent($friend, $event);

            // Deux amis sur le même événement : plutôt que de l'annoncer, on leur
            // demande s'ils y vont ensemble — l'information a plus de valeur, et
            // c'est la seule façon de la connaître
            if ($theirs !== null) {
                $this->askTogether($participation, $theirs);
                continue;
            }

            $this->notifier->dispatch(
                $friend,
                NotificationType::FriendActivity,
                $author->getDisplayName() . ($upcoming ? ' ira à un événement' : ' était à un événement'),
                $name,
                $this->eventUrl($event),
                ['eventId' => (string) $event->getId()],
                dedupeKey: 'activity:' . $participation->getId(),
            );
        }
    }

    /**
     * Demande aux deux participants s'ils y vont ensemble. Chacun a sa question,
     * qui portera sa réponse : la relation n'est créée que si les deux répondent
     * oui (voir EventController::togetherYes). Ne demande rien s'ils sont déjà
     * liés, ou si l'un des deux a déjà été interrogé pour cette paire.
     */
    private function askTogether(EventParticipation $a, EventParticipation $b): void
    {
        if ($this->alreadyCompanions($a, $b)) {
            return;
        }

        $event = $a->getEvent();
        $upcoming = $event->getDate() >= new \DateTimeImmutable('today');
        $name = $this->eventName($event);

        // Clé commune à la paire, dans un ordre stable : que ce soit A ou B qui
        // ajoute en premier, la question n'est posée qu'une fois à chacun
        $ids = [(string) $a->getUser()->getId(), (string) $b->getUser()->getId()];
        sort($ids);
        $key = 'together:' . $event->getId() . ':' . implode(':', $ids);

        foreach ([[$a, $b], [$b, $a]] as [$self, $other]) {
            $otherUser = $other->getUser();
            $this->notifier->dispatch(
                $self->getUser(),
                NotificationType::FriendSameEvent,
                ($upcoming ? 'Tu y vas avec ' : 'Tu y étais avec ') . $otherUser->getDisplayName() . ' ?',
                '@' . $otherUser->getUsername() . ($upcoming ? ' va aussi à ' : ' était aussi à ') . $name . '.',
                $this->eventUrl($event),
                [
                    'eventId' => (string) $event->getId(),
                    'otherUserId' => (string) $otherUser->getId(),
                    'eventName' => $name,
                ],
                dedupeKey: $key,
            );
        }
    }

    /** L'un des deux a-t-il déjà tagué l'autre comme accompagnant ? */
    private function alreadyCompanions(EventParticipation $a, EventParticipation $b): bool
    {
        return $this->tags($a, $b->getUser()) || $this->tags($b, $a->getUser());
    }

    private function tags(EventParticipation $participation, User $user): bool
    {
        foreach ($participation->getFriends() as $f) {
            if (($f['type'] ?? '') === 'app' && ($f['userId'] ?? '') === (string) $user->getId()) {
                return true;
            }
        }

        return false;
    }

    /** Un ami vient de raconter un événement : note, commentaire ou photo. */
    public function announceMemory(EventParticipation $participation): void
    {
        if ($participation->getVisibility() === 'private') {
            return;
        }

        $author = $participation->getUser();
        $name = $this->eventName($participation->getEvent());

        foreach ($this->audience($participation) as $friend) {
            $this->notifier->dispatch(
                $friend,
                NotificationType::FriendActivity,
                $author->getDisplayName() . ' a raconté ' . $name,
                $this->memoryTeaser($participation),
                $this->eventUrl($participation->getEvent()),
                ['eventId' => (string) $participation->getEvent()->getId()],
                // Une seule annonce par souvenir : les retouches suivantes ne repoussent pas
                dedupeKey: 'memory:' . $participation->getId(),
            );
        }
    }

    /**
     * Les amis in-app confirmés de l'auteur, moins ceux tagués sur la
     * participation (déjà prévenus par leur notification de tag).
     *
     * @return list<User>
     */
    private function audience(EventParticipation $participation): array
    {
        $author = $participation->getUser();

        $tagged = [];
        foreach ($participation->getFriends() as $f) {
            if (($f['type'] ?? '') === 'app' && isset($f['userId'])) {
                $tagged[(string) $f['userId']] = true;
            }
        }

        $audience = [];
        foreach ($this->friendRepo->findConfirmedFriends($author) as $rel) {
            $other = $rel->getOwner()->getId()->equals($author->getId())
                ? $rel->getFriendUser()
                : $rel->getOwner();

            if ($other === null || isset($tagged[(string) $other->getId()])) {
                continue;
            }
            $audience[(string) $other->getId()] = $other;
        }

        return array_values($audience);
    }

    private function memoryTeaser(EventParticipation $participation): string
    {
        if ($comment = $participation->getComment()) {
            return '« ' . mb_strimwidth($comment, 0, 120, '… ') . ' »';
        }
        if ($rating = $participation->getRating()) {
            return str_repeat('★', $rating) . str_repeat('☆', 5 - $rating);
        }

        return 'Une photo à voir.';
    }

    private function eventName(Event $event): string
    {
        return $event->getArtistName()
            ?? $event->getTournamentName()
            ?? $event->getTeams()
            ?? 'un événement';
    }

    private function eventUrl(Event $event): string
    {
        return $this->urls->generate('app_event_show', ['id' => (string) $event->getId()]);
    }
}
