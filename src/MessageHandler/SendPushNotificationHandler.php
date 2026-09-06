<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\SendPushNotification;
use App\Service\NotificationService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class SendPushNotificationHandler
{
    public function __construct(
        private NotificationService $pushService,
        private LoggerInterface $logger,
    ) {}

    public function __invoke(SendPushNotification $message): void
    {
        $result = $this->pushService->sendNotification(
            $message->title,
            $message->body,
            $message->userId,
            $message->url,
        );

        // En prod, monolog n'écrit qu'à partir du niveau error : un warning ici
        // se perdrait, et un push qui n'arrive jamais ne laisserait aucune trace
        if (($result['failed'] ?? 0) > 0) {
            $this->logger->error('Push partiellement échoué', [
                'userId' => $message->userId,
                'sent' => $result['sent'] ?? 0,
                'failed' => $result['failed'],
            ]);
        }
    }
}
