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

        if (($result['failed'] ?? 0) > 0) {
            $this->logger->warning('Push partiellement échoué', [
                'userId' => $message->userId,
                'sent' => $result['sent'] ?? 0,
                'failed' => $result['failed'],
            ]);
        }
    }
}
