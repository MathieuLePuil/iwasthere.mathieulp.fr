<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\PushSubscriptionRepository;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

/** L'envoi Web Push proprement dit. Appelé par le worker (SendPushNotificationHandler), jamais dans une requête. */
class NotificationService
{
    private WebPush $webPush;

    public function __construct(
        private readonly PushSubscriptionRepository $subscriptions,
        string $vapidPublicKey,
        string $vapidPrivateKey,
        string $vapidSubject,
    ) {
        $this->webPush = new WebPush([
            'VAPID' => [
                'subject' => $vapidSubject,
                'publicKey' => $vapidPublicKey,
                'privateKey' => $vapidPrivateKey,
            ],
        ]);
    }

    /** @return array{sent: int, failed: int, message?: string} */
    public function sendNotification(string $title, string $body, ?string $userId = null, ?string $url = null, ?string $ackUrl = null): array
    {
        $subscriptions = $userId === null
            ? $this->subscriptions->findAll()
            : $this->subscriptions->findForUserId($userId);

        if ($subscriptions === []) {
            return ['sent' => 0, 'failed' => 0, 'message' => 'No subscriptions found'];
        }

        $payload = json_encode([
            'title' => $title,
            'body' => $body,
            'icon' => '/icons/icon-192.png',
            'url' => $url ?? '/home',
            'ackUrl' => $ackUrl,
        ]);

        foreach ($subscriptions as $sub) {
            $this->webPush->queueNotification(Subscription::create($sub->toArray()), $payload);
        }

        $sent = 0;
        $failed = 0;
        $expired = [];
        foreach ($this->webPush->flush() as $report) {
            if ($report->isSuccess()) {
                $sent++;
                continue;
            }
            $failed++;
            if ($report->isSubscriptionExpired()) {
                $expired[] = $report->getEndpoint();
            }
        }

        // Un endpoint que le serveur de push ne connaît plus (410/404) ne reviendra pas
        $this->subscriptions->deleteByEndpoints($expired);

        return ['sent' => $sent, 'failed' => $failed];
    }
}
