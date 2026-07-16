<?php

declare(strict_types=1);

namespace App\Reaction;

use App\Entity\EventParticipation;
use App\Entity\Reaction;
use App\Entity\User;
use App\Notification\NotificationDispatcher;
use App\Notification\NotificationType;
use App\Repository\FriendRepository;
use App\Repository\ReactionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Pose et retire les réactions, et prévient l'auteur de la participation.
 *
 * La règle de visibilité est celle du feed (FeedService::resolveVisibleFriends) :
 * on ne peut réagir qu'à ce qu'on aurait pu y voir — ami in-app confirmé, profil
 * non privé, participation non privée. La rejouer ici plutôt que de s'en remettre
 * au fait que le bouton ne soit pas affiché : la route est appelable directement.
 */
final class ReactionService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ReactionRepository $reactions,
        private readonly FriendRepository $friends,
        private readonly NotificationDispatcher $notifier,
        private readonly UrlGeneratorInterface $urls,
    ) {}

    /**
     * Peut-on réagir à cette participation ? Il faut être un ami confirmé de son
     * auteur, et ce n'est jamais la sienne.
     *
     * Que le compte soit public ou privé ne change rien : on ne réagit que dans le
     * feed, qui ne montre que des amis. Un inconnu qui voit un profil public le voit
     * en lecture seule.
     */
    public function canReact(User $user, EventParticipation $participation): bool
    {
        $author = $participation->getUser();

        return !$author->getId()->equals($user->getId())
            && $this->friends->areFriends($user, $author);
    }

    /**
     * Ajoute la réaction si elle n'y est pas, la retire sinon.
     *
     * @param string $emoji déjà passé par ReactionEmoji::normalize()
     *
     * @return array<string, array{count: int, mine: bool}> le nouvel état, pour
     *     que l'appelant réponde ce que la base dit vraiment plutôt que ce que le
     *     client avait supposé
     */
    public function toggle(User $user, EventParticipation $participation, string $emoji): array
    {
        $existing = $this->reactions->findOneFor($participation, $user, $emoji);

        if ($existing !== null) {
            $this->em->remove($existing);
            $this->em->flush();

            return $this->reactions->stateForOne($participation, $user);
        }

        $reaction = new Reaction();
        $reaction->setParticipation($participation)
            ->setUser($user)
            ->setEmoji($emoji);
        $this->em->persist($reaction);
        $this->em->flush();

        $this->announce($user, $participation, $emoji);

        return $this->reactions->stateForOne($participation, $user);
    }

    /**
     * Une seule notification par (participation, auteur de la réaction) : la clé de
     * dédoublonnage ignore l'emoji, sinon retirer puis reposer une réaction — ou en
     * essayer plusieurs de suite — repousserait à chaque fois.
     */
    private function announce(User $reactor, EventParticipation $participation, string $emoji): void
    {
        $event = $participation->getEvent();
        $name = $event->getArtistName()
            ?? $event->getTournamentName()
            ?? $event->getTeams()
            ?? 'un événement';

        $this->notifier->dispatch(
            $participation->getUser(),
            NotificationType::FriendReaction,
            $reactor->getDisplayName() . ' a réagi ' . $emoji,
            $name,
            $this->urls->generate('app_event_show', ['id' => (string) $event->getId()]),
            ['eventId' => (string) $event->getId()],
            dedupeKey: 'reaction:' . $participation->getId() . ':' . $reactor->getId(),
        );
    }
}
