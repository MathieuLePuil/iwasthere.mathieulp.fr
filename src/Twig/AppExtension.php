<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\Event;
use App\Event\EventCategory;
use App\Event\EventType;
use App\Notification\NotificationType;
use App\Reaction\ReactionEmoji;
use App\EventListener\SecurityHeadersListener;
use App\Repository\NotificationRepository;
use App\Service\EventImageService;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

class AppExtension extends AbstractExtension
{
    public function __construct(
        private readonly NotificationRepository $notifRepo,
        private readonly Security $security,
        private readonly EventImageService $images,
        private readonly RequestStack $requests,
    ) {}

    public function getFunctions(): array
    {
        return [
            new TwigFunction('unread_notifications_count', $this->getUnreadCount(...)),
            new TwigFunction('notification_type', $this->notificationType(...)),
            new TwigFunction('greeting', $this->greeting(...)),
            new TwigFunction('reaction_emojis', $this->reactionEmojis(...)),
            new TwigFunction('event_hue', $this->eventHue(...)),
            new TwigFunction('event_type', $this->eventType(...)),
            new TwigFunction('event_types', $this->eventTypes(...)),
            new TwigFunction('csp_nonce', $this->cspNonce(...)),
        ];
    }

    /** Le nonce de la requête, à poser sur les rares scripts inline (voir SecurityHeadersListener). */
    public function cspNonce(): string
    {
        return (string) $this->requests->getMainRequest()?->attributes->get(SecurityHeadersListener::NONCE_ATTRIBUTE, '');
    }

    /** Le type d'un événement (ou d'une valeur brute), null s'il n'est pas au catalogue. */
    public function eventType(Event|string|null $type): ?EventType
    {
        if ($type instanceof Event) {
            $type = $type->getType();
        }

        return $type === null ? null : EventType::tryFrom($type);
    }

    /**
     * Les types d'une catégorie ('music', 'sport'), ou tous.
     *
     * @return list<EventType>
     */
    public function eventTypes(?string $category = null): array
    {
        $cat = $category === null ? null : EventCategory::tryFrom($category);

        return $cat === null ? EventType::cases() : EventType::ofCategory($cat);
    }

    /**
     * Teinte (0-359) dérivée du nom de l'évènement, de façon déterministe et
     * stable : deux affichages du même artiste/tournoi donnent toujours la même
     * couleur. Sert à colorer subtilement les posters sans photo (cf. --evt-hue
     * / --evt-tint dans app.css) pour que chaque miniature ait sa propre teinte
     * plutôt que le dégradé unique et un peu terne du type.
     */
    public function eventHue(Event $event): int
    {
        $name = $event->getArtistName()
            ?? $event->getTournamentName()
            ?? $event->getTeams()
            ?? 'Événement';

        // crc32 : hash déterministe, réparti et sans dépendance externe. Le
        // décalage doré (137°) étale les teintes de noms proches sur la roue
        // plutôt que de les tasser côte à côte.
        $hash = crc32(mb_strtolower(trim($name)));

        return (int) (($hash * 137) % 360);
    }

    /**
     * Les emojis proposés dans le sélecteur de réaction. Ce ne sont que des
     * raccourcis : n'importe quel emoji est accepté, la liste n'a rien de fermé.
     *
     * @return list<string>
     */
    public function reactionEmojis(): array
    {
        return ReactionEmoji::SUGGESTIONS;
    }

    /**
     * Salutation selon l'heure de Paris — le serveur tourne en UTC, on ne peut
     * pas se reposer sur le fuseau ambiant de PHP (cf. SendEventRemindersCommand).
     */
    public function greeting(): string
    {
        $hour = (int) (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Paris')))->format('G');

        return match (true) {
            $hour < 6  => 'Bonsoir',
            $hour < 12 => 'Bonjour',
            $hour < 18 => 'Bon après-midi',
            default    => 'Bonsoir',
        };
    }

    /**
     * Le cas du catalogue derrière la colonne `type`, pour que le fil lise son
     * icône et sa couleur à la source plutôt que de les redéclarer.
     * null si la notification est d'un type retiré du catalogue depuis.
     */
    public function notificationType(string $type): ?NotificationType
    {
        return NotificationType::tryFrom($type);
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('time_ago', $this->timeAgo(...)),
            // La miniature d'une photo d'événement (480 px) pour les cartes ; la photo
            // elle-même pour celles d'avant les miniatures.
            new TwigFilter('thumb', $this->images->thumbUrl(...)),
        ];
    }

    public function timeAgo(\DateTimeInterface $date): string
    {
        $diff = time() - $date->getTimestamp();

        return match (true) {
            $diff < 60 => "à l'instant",
            $diff < 3600 => 'il y a ' . intdiv($diff, 60) . ' min',
            $diff < 86400 => 'il y a ' . intdiv($diff, 3600) . ' h',
            $diff < 172800 => 'hier',
            $diff < 604800 => 'il y a ' . intdiv($diff, 86400) . ' j',
            default => $date->format('d/m/Y'),
        };
    }

    public function getUnreadCount(): int
    {
        if (!$user = $this->security->getUser()) {
            return 0;
        }
        return $this->notifRepo->countUnread($user);
    }
}
