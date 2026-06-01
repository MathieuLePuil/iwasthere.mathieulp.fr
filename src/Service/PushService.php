<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use App\Repository\PushSubscriptionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

class PushService
{
    public function __construct(
        private readonly PushSubscriptionRepository $subscriptionRepo,
        private readonly EntityManagerInterface $em,
        private readonly string $vapidPublicKey,
        private readonly string $vapidPrivateKey,
        private readonly string $vapidSubject,
    ) {}

    /**
     * Sends a push notification to every active subscription owned by the user.
     * Expired subscriptions are removed from the database automatically.
     *
     * @return array{sent: int, failed: int}
     */
    public function sendToUser(User $user, string $title, string $body, string $url = '/'): array
    {
        if (empty($this->vapidPublicKey) || empty($this->vapidPrivateKey)) {
            return ['sent' => 0, 'failed' => 0];
        }

        $subscriptions = $this->subscriptionRepo->findByUser($user);
        if (empty($subscriptions)) {
            return ['sent' => 0, 'failed' => 0];
        }

        $webPush = new WebPush([
            'VAPID' => [
                'subject' => $this->vapidSubject ?: 'mailto:noreply@iwasthere.app',
                'publicKey' => $this->vapidPublicKey,
                'privateKey' => $this->vapidPrivateKey,
            ],
        ]);

        $payload = json_encode([
            'title' => $title,
            'body' => $body,
            'url' => $url,
        ]);

        $entityByEndpoint = [];
        foreach ($subscriptions as $sub) {
            $entityByEndpoint[$sub->getEndpoint()] = $sub;
            $webPush->queueNotification(
                Subscription::create([
                    'endpoint' => $sub->getEndpoint(),
                    'keys' => [
                        'p256dh' => $sub->getP256dh(),
                        'auth' => $sub->getAuth(),
                    ],
                ]),
                $payload,
            );
        }

        $sent = 0;
        $failed = 0;

        foreach ($webPush->flush() as $report) {
            if ($report->isSuccess()) {
                $sent++;
                continue;
            }
            $failed++;

            if ($report->isSubscriptionExpired()) {
                $endpoint = $report->getEndpoint();
                if (isset($entityByEndpoint[$endpoint])) {
                    $this->em->remove($entityByEndpoint[$endpoint]);
                }
            }
        }

        $this->em->flush();

        return ['sent' => $sent, 'failed' => $failed];
    }
}
