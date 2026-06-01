<?php

declare(strict_types=1);

namespace App\Service;

use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

class PushService
{
    private string $subsFile;

    public function __construct(
        string $projectDir,
        private readonly string $vapidPublicKey,
        private readonly string $vapidPrivateKey,
        private readonly string $vapidSubject,
    ) {
        $this->subsFile = $projectDir . '/var/subscriptions.json';
    }

    /**
     * Send a push notification to every stored subscription.
     * Expired subscriptions are removed from the file.
     *
     * @return array{sent: int, failed: int}
     */
    public function sendToAll(string $title, string $body, string $url = '/'): array
    {
        if (empty($this->vapidPublicKey) || empty($this->vapidPrivateKey)) {
            return ['sent' => 0, 'failed' => 0];
        }

        $subs = $this->getSubscriptions();
        if (empty($subs)) {
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

        foreach ($subs as $sub) {
            $webPush->queueNotification(Subscription::create($sub), $payload);
        }

        $sent = 0;
        $failed = 0;
        $expiredEndpoints = [];

        foreach ($webPush->flush() as $report) {
            if ($report->isSuccess()) {
                $sent++;
                continue;
            }
            $failed++;
            if ($report->isSubscriptionExpired()) {
                $expiredEndpoints[] = $report->getEndpoint();
            }
        }

        if ($expiredEndpoints) {
            $remaining = array_values(array_filter(
                $subs,
                static fn (array $s) => !in_array($s['endpoint'] ?? '', $expiredEndpoints, true),
            ));
            @file_put_contents($this->subsFile, json_encode($remaining, JSON_PRETTY_PRINT));
        }

        return ['sent' => $sent, 'failed' => $failed];
    }

    /**
     * Backwards-compatible alias kept so existing controller calls keep working.
     * Per the new design, every push goes to everyone.
     */
    public function sendToUser(mixed $user, string $title, string $body, string $url = '/'): array
    {
        return $this->sendToAll($title, $body, $url);
    }

    /** @return list<array<string, mixed>> */
    private function getSubscriptions(): array
    {
        if (!file_exists($this->subsFile)) {
            return [];
        }
        $data = json_decode((string) @file_get_contents($this->subsFile), true);
        return is_array($data) ? $data : [];
    }
}
