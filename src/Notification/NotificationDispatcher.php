<?php

declare(strict_types=1);

namespace App\Notification;

use App\Entity\Notification;
use App\Entity\User;
use App\Message\SendPushNotification;
use App\Repository\NotificationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Le point d'entrée unique pour notifier quelqu'un.
 *
 * Une notification, c'est deux choses : une trace dans le fil in-app et un push.
 * Le fil est toujours écrit — c'est l'historique, il doit être exhaustif. Le push
 * n'est envoyé que si l'utilisateur veut ce type-là (User::wantsPush). Décocher
 * une catégorie rend donc la notification silencieuse, pas invisible.
 *
 * Ne jamais construire un Notification ni appeler NotificationService à la main
 * ailleurs : c'est ainsi que les préférences avaient fini par n'être respectées
 * nulle part.
 */
final class NotificationDispatcher
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly NotificationRepository $notifRepo,
        private readonly MessageBusInterface $bus,
    ) {}

    /**
     * @param string|null $pushBody texte du push s'il doit différer du fil
     *     (le fil vouvoie l'historique, le push tutoie — usage existant)
     * @param array<string, mixed> $data charge utile (ids pour les actions du fil)
     *
     * @return bool la notification a-t-elle été créée ? (false si déjà envoyée)
     */
    public function dispatch(
        User $recipient,
        NotificationType $type,
        string $title,
        string $body,
        ?string $url = null,
        array $data = [],
        ?string $pushBody = null,
        ?string $dedupeKey = null,
    ): bool {
        if (!$this->record($recipient, $type, $title, $body, $url, $data, $dedupeKey)) {
            return false;
        }

        $this->push($recipient, $type, $title, $pushBody ?? $body, $url);

        return true;
    }

    /**
     * Écrit dans le fil sans pousser. Pour les envois groupés : on inscrit chaque
     * élément (chacun a ses actions propres) puis on pousse une fois pour le lot.
     *
     * @param array<string, mixed> $data
     *
     * @return bool false si `$dedupeKey` a déjà été inscrite
     */
    public function record(
        User $recipient,
        NotificationType $type,
        string $title,
        string $body,
        ?string $url = null,
        array $data = [],
        ?string $dedupeKey = null,
    ): bool {
        if ($dedupeKey !== null && $this->notifRepo->existsForDedupeKey($recipient, $type->value, $dedupeKey)) {
            return false;
        }

        if ($dedupeKey !== null) {
            $data['dedupeKey'] = $dedupeKey;
        }
        if ($url !== null) {
            $data['url'] = $url;
        }

        $notif = new Notification();
        $notif->setRecipient($recipient)
            ->setType($type->value)
            ->setTitle($title)
            ->setBody($body)
            ->setData($data === [] ? null : $data);
        $this->em->persist($notif);
        $this->em->flush();

        return true;
    }

    /** Pousse sans rien inscrire, si l'utilisateur veut ce type. L'envoi part en asynchrone. */
    public function push(User $recipient, NotificationType $type, string $title, string $body, ?string $url = null): void
    {
        if (!$recipient->wantsPush($type)) {
            return;
        }

        $this->bus->dispatch(new SendPushNotification($title, $body, (string) $recipient->getId(), $url));
    }
}
