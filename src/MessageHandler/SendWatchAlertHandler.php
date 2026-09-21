<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\WatchNotification;
use App\Message\SendWatchAlert;
use App\Message\SendWatchAlertEmail;
use App\Notification\NotificationDispatcher;
use App\Repository\PushSubscriptionRepository;
use App\Repository\WatchNotificationRepository;
use App\Ticketmaster\AckUrlSigner;
use App\Ticketmaster\AlertComposer;
use App\Ticketmaster\WatchChannel;
use App\Ticketmaster\WatchNotificationStatus;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Uid\Uuid;

/**
 * Envoie un lot d'alertes billetterie.
 *
 * Le fil in-app reçoit une entrée par événement (chacune avec son lien
 * Ticketmaster) ; le push part une fois pour le lot. Web Push en principal,
 * e-mail en secours si le service worker n'a pas accusé réception sous
 * 5 minutes — ou tout de suite si l'utilisateur n'a pas d'abonnement push,
 * a coupé ce type de push, ou a choisi l'e-mail pour cette veille.
 */
#[AsMessageHandler]
final readonly class SendWatchAlertHandler
{
    public const EMAIL_FALLBACK_DELAY_MS = 5 * 60 * 1000;

    public function __construct(
        private WatchNotificationRepository $notifications,
        private PushSubscriptionRepository $subscriptions,
        private NotificationDispatcher $notifier,
        private AckUrlSigner $signer,
        private MessageBusInterface $bus,
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
    ) {}

    public function __invoke(SendWatchAlert $message): void
    {
        $batch = Uuid::fromString($message->batch);
        $items = array_values(array_filter(
            $this->notifications->findByBatch($batch),
            static fn (WatchNotification $n) => $n->getStatus() === WatchNotificationStatus::Queued,
        ));
        if ($items === []) {
            $this->logger->warning('Lot d\'alertes introuvable ou déjà traité', ['batch' => $message->batch]);

            return;
        }

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $user = $items[0]->getWatch()->getUser();
        $alert = AlertComposer::compose($items, $now);

        foreach ($alert['items'] as $line) {
            $this->notifier->record($user, $alert['type'], $line['title'], $line['body'], $line['url'], ['batch' => $message->batch]);
        }

        $viaEmail = $items[0]->getWatch()->getChannel() === WatchChannel::Email
            || $this->subscriptions->findForUserId((string) $user->getId()) === [];

        $pushed = !$viaEmail && $this->notifier->push(
            $user,
            $alert['type'],
            $alert['title'],
            $alert['body'],
            $alert['url'],
            $this->signer->url($batch),
        );

        if ($pushed) {
            $this->bus->dispatch(new SendWatchAlertEmail($message->batch, fallback: true), [new DelayStamp(self::EMAIL_FALLBACK_DELAY_MS)]);
        } else {
            $this->bus->dispatch(new SendWatchAlertEmail($message->batch, fallback: false));
        }

        foreach ($items as $n) {
            $n->markSent();
        }
        $this->em->flush();
    }
}
