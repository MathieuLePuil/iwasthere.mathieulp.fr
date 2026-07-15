<?php

declare(strict_types=1);

namespace App\Twig;

use App\Notification\NotificationType;
use App\Repository\NotificationRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

class AppExtension extends AbstractExtension
{
    public function __construct(
        private readonly NotificationRepository $notifRepo,
        private readonly Security $security,
    ) {}

    public function getFunctions(): array
    {
        return [
            new TwigFunction('unread_notifications_count', $this->getUnreadCount(...)),
            new TwigFunction('notification_type', $this->notificationType(...)),
            new TwigFunction('greeting', $this->greeting(...)),
        ];
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
