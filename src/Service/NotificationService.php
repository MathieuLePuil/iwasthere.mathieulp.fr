<?php

declare(strict_types=1);

namespace App\Service;

use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

class NotificationService
{
    private WebPush $webPush;
    private string $subscriptionsFile;

    public function __construct(
        string $vapidPublicKey,
        string $vapidPrivateKey,
        string $vapidSubject,
        string $projectDir,
    ) {
        $auth = [
            'VAPID' => [
                'subject' => $vapidSubject,
                'publicKey' => $vapidPublicKey,
                'privateKey' => $vapidPrivateKey,
            ],
        ];

        $this->webPush = new WebPush($auth);
        $this->subscriptionsFile = $projectDir . '/var/subscriptions.json';
    }

    public function sendNotification(string $title, string $body, ?string $userId = null, ?string $url = null): array
    {
        $subscriptions = $this->getSubscriptions();

        if ($userId !== null) {
            $subscriptions = array_values(array_filter(
                $subscriptions,
                fn ($sub) => ($sub['userId'] ?? null) === $userId,
            ));
        }

        if (empty($subscriptions)) {
            return ['sent' => 0, 'failed' => 0, 'message' => 'No subscriptions found'];
        }

        $payload = json_encode([
            'title' => $title,
            'body' => $body,
            'icon' => '/icons/icon-192.png',
            'url' => $url ?? '/',
        ]);

        $sent = 0;
        $failed = 0;
        $expiredEndpoints = [];

        foreach ($subscriptions as $sub) {
            $this->webPush->queueNotification(Subscription::create($sub), $payload);
        }

        foreach ($this->webPush->flush() as $report) {
            $endpoint = $report->getEndpoint();
            if ($report->isSuccess()) {
                $sent++;
            } else {
                $failed++;
                if (method_exists($report, 'isSubscriptionExpired') && $report->isSubscriptionExpired()) {
                    $expiredEndpoints[] = $endpoint;
                }
            }
        }

        if (!empty($expiredEndpoints)) {
            $filtered = array_values(array_filter(
                $subscriptions,
                fn ($sub) => !in_array($sub['endpoint'] ?? '', $expiredEndpoints, true),
            ));
            $this->saveSubscriptions($filtered);
        }

        return ['sent' => $sent, 'failed' => $failed];
    }

    private function getSubscriptions(): array
    {
        if (!file_exists($this->subscriptionsFile)) {
            return [];
        }
        $content = file_get_contents($this->subscriptionsFile);
        return json_decode($content, true) ?? [];
    }

    private function saveSubscriptions(array $subscriptions): void
    {
        file_put_contents($this->subscriptionsFile, json_encode($subscriptions));
    }
}
