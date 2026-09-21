<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\WatchNotification;
use App\Message\SendWatchAlertEmail;
use App\Repository\WatchNotificationRepository;
use App\Ticketmaster\AlertComposer;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Uid\Uuid;
use Twig\Environment;

/**
 * L'e-mail d'une alerte billetterie. En secours du push, il n'est envoyé que
 * si aucune échéance du lot n'a été acquittée par le service worker — le
 * message arrive cinq minutes après le push (DelayStamp), le temps que
 * l'accusé remonte.
 */
#[AsMessageHandler]
final readonly class SendWatchAlertEmailHandler
{
    public function __construct(
        private WatchNotificationRepository $notifications,
        private MailerInterface $mailer,
        private Environment $twig,
        private LoggerInterface $logger,
    ) {}

    public function __invoke(SendWatchAlertEmail $message): void
    {
        $items = $this->notifications->findByBatch(Uuid::fromString($message->batch));
        if ($items === []) {
            return;
        }

        if ($message->fallback) {
            foreach ($items as $n) {
                if ($n->getAcknowledgedAt() !== null) {
                    return;
                }
            }
            $this->logger->info('Push sans accusé de réception : e-mail de secours', ['batch' => $message->batch]);
        }

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $user = $items[0]->getWatch()->getUser();
        $alert = AlertComposer::compose($items, $now);

        $html = $this->twig->render('emails/ticket_alert.html.twig', [
            'user' => $user,
            'alert' => $alert,
            'events' => array_map(static fn (WatchNotification $n) => $n->getWatch()->getEvent(), $items),
            'fallback' => $message->fallback,
        ]);

        $this->mailer->send((new Email())
            ->from(new Address('noreply@iwasthereapp.app', 'IWasThere'))
            ->to(new Address($user->getEmail(), $user->getDisplayName()))
            ->subject($alert['title'])
            ->html($html));
    }
}
