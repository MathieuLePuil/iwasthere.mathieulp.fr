<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Routing\Attribute\Route;

class PushController extends AbstractController
{
    private function subsFile(KernelInterface $kernel): string
    {
        return $kernel->getProjectDir() . '/var/subscriptions.json';
    }

    private function debugLog(KernelInterface $kernel, string $line): void
    {
        $file = $kernel->getProjectDir() . '/var/push.log';
        @file_put_contents(
            $file,
            '[' . date('Y-m-d H:i:s') . '] ' . $line . PHP_EOL,
            FILE_APPEND,
        );
    }

    #[Route('/push/ping', name: 'app_push_ping', methods: ['GET'])]
    public function ping(KernelInterface $kernel): JsonResponse
    {
        $file = $this->subsFile($kernel);
        $dir = dirname($file);
        $count = 0;
        if (file_exists($file)) {
            $count = count(json_decode((string) @file_get_contents($file), true) ?? []);
        }

        return $this->json([
            'status' => 'ok',
            'time' => date(\DateTimeInterface::ATOM),
            'subs_file' => $file,
            'subs_dir_writable' => is_writable($dir),
            'subs_file_writable' => file_exists($file) ? is_writable($file) : null,
            'subs_count' => $count,
        ]);
    }

    /**
     * Public endpoint : the PWA POSTs the PushSubscription JSON here.
     * Stored as-is in var/subscriptions.json, deduped by endpoint.
     */
    #[Route('/push/subscribe', name: 'app_push_subscribe', methods: ['POST'])]
    public function subscribe(Request $request, KernelInterface $kernel): JsonResponse
    {
        $raw = $request->getContent();
        $sub = json_decode($raw, true);

        $this->debugLog($kernel, 'subscribe in: ' . strlen($raw) . ' bytes, endpoint=' . substr((string)($sub['endpoint'] ?? '(none)'), 0, 60));

        if (!is_array($sub) || empty($sub['endpoint']) || empty($sub['keys']['p256dh']) || empty($sub['keys']['auth'])) {
            $this->debugLog($kernel, 'subscribe REJECT: invalid payload');
            return $this->json(['status' => 'error', 'message' => 'Invalid subscription payload'], 400);
        }

        $file = $this->subsFile($kernel);
        $dir = dirname($file);

        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        if (!is_writable($dir)) {
            $this->debugLog($kernel, 'subscribe FAIL: dir not writable ' . $dir);
            return $this->json([
                'status' => 'error',
                'message' => 'Server cannot write to ' . $dir,
            ], 500);
        }

        $list = [];
        if (file_exists($file)) {
            $list = json_decode((string) @file_get_contents($file), true) ?? [];
        }

        $exists = false;
        foreach ($list as $existing) {
            if (($existing['endpoint'] ?? '') === $sub['endpoint']) {
                $exists = true;
                break;
            }
        }

        if (!$exists) {
            $list[] = $sub;
            $ok = @file_put_contents($file, json_encode($list, JSON_PRETTY_PRINT));
            if ($ok === false) {
                $this->debugLog($kernel, 'subscribe FAIL: file_put_contents');
                return $this->json([
                    'status' => 'error',
                    'message' => 'Cannot write file ' . $file,
                ], 500);
            }
            $this->debugLog($kernel, 'subscribe ADDED, total=' . count($list));
        } else {
            $this->debugLog($kernel, 'subscribe DEDUPED, total=' . count($list));
        }

        return $this->json(['status' => 'success', 'total' => count($list)]);
    }
}
