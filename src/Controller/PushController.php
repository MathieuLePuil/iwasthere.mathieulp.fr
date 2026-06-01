<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

class PushController extends AbstractController
{
    #[Route('/subscribe', name: 'app_subscribe', methods: ['POST'])]
    public function subscribe(Request $request): JsonResponse
    {
        $subscription = json_decode($request->getContent(), true);
        if (!$subscription) {
            return new JsonResponse(['status' => 'error'], 400);
        }

        $file = $this->getParameter('kernel.project_dir') . '/var/subscriptions.json';
        $subscriptions = [];
        if (file_exists($file)) {
            $subscriptions = json_decode(file_get_contents($file), true) ?? [];
        }

        $exists = false;
        foreach ($subscriptions as $sub) {
            if (($sub['endpoint'] ?? '') === ($subscription['endpoint'] ?? '')) {
                $exists = true;
                break;
            }
        }

        if (!$exists) {
            $subscriptions[] = $subscription;
            file_put_contents($file, json_encode($subscriptions));
        }

        return new JsonResponse(['status' => 'success']);
    }
}
