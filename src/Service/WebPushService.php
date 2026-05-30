<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use App\Repository\PushSubscriptionRepository;

class WebPushService
{
    public function __construct(
        private readonly PushSubscriptionRepository $subscriptionRepo,
        private readonly string $vapidPublicKey,
        private readonly string $vapidPrivateKey,
        private readonly string $vapidSubject,
    ) {}

    public function sendToUser(User $user, string $title, string $body, string $url = '/notifications'): void
    {
        if (!class_exists('Minishlink\WebPush\WebPush') || empty($this->vapidPublicKey) || empty($this->vapidPrivateKey)) {
            return;
        }

        $subscriptions = $this->subscriptionRepo->findByUser($user);
        if (empty($subscriptions)) {
            return;
        }

        try {
            $webPush = new \Minishlink\WebPush\WebPush([
                'VAPID' => [
                    'subject' => $this->vapidSubject ?: 'mailto:noreply@iwasthere.app',
                    'publicKey' => $this->vapidPublicKey,
                    'privateKey' => $this->vapidPrivateKey,
                ],
            ]);

            $payload = json_encode(['title' => $title, 'body' => $body, 'url' => $url]);

            foreach ($subscriptions as $sub) {
                $subscription = \Minishlink\WebPush\Subscription::create([
                    'endpoint' => $sub->getEndpoint(),
                    'keys' => [
                        'p256dh' => $sub->getP256dh(),
                        'auth' => $sub->getAuth(),
                    ],
                ]);
                $webPush->queueNotification($subscription, $payload);
            }

            foreach ($webPush->flush() as $report) {
                // Expired/invalid subscriptions could be removed here
                if (!$report->isSuccess() && $report->isSubscriptionExpired()) {
                    $sub = $this->subscriptionRepo->findByEndpoint(
                        $report->getRequest()->getUri()->__toString()
                    );
                    if ($sub) {
                        $this->subscriptionRepo->getEntityManager()->remove($sub);
                        $this->subscriptionRepo->getEntityManager()->flush();
                    }
                }
            }
        } catch (\Throwable) {
            // Silently fail — push notification is non-critical
        }
    }
}
