<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\PushSubscription;
use App\Repository\PushSubscriptionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class PushController extends AbstractController
{
    /**
     * Public health check — confirms the route is registered (i.e. cache cleared).
     * Hit it directly in a browser : https://iwasthere.mathieulp.fr/push/ping
     */
    #[Route('/push/ping', name: 'app_push_ping', methods: ['GET'])]
    public function ping(): JsonResponse
    {
        return $this->json([
            'status' => 'ok',
            'time'   => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ]);
    }

    #[IsGranted('ROLE_USER')]
    #[Route('/push/subscribe', name: 'app_push_subscribe', methods: ['POST'])]
    public function subscribe(
        Request $request,
        EntityManagerInterface $em,
        PushSubscriptionRepository $repo,
        LoggerInterface $logger,
    ): JsonResponse {
        $raw = $request->getContent();
        $data = json_decode($raw, true);

        $endpoint = $data['endpoint'] ?? null;
        $p256dh = $data['keys']['p256dh'] ?? null;
        $auth = $data['keys']['auth'] ?? null;

        $logger->info('push.subscribe', [
            'user' => $this->getUser()?->getUserIdentifier(),
            'endpoint_host' => $endpoint ? (parse_url($endpoint, PHP_URL_HOST) ?: 'invalid') : null,
            'endpoint_length' => $endpoint ? strlen($endpoint) : 0,
            'has_p256dh' => (bool) $p256dh,
            'has_auth' => (bool) $auth,
            'raw_length' => strlen($raw),
        ]);

        if (!$endpoint || !$p256dh || !$auth) {
            return $this->json([
                'status' => 'error',
                'message' => 'Invalid subscription payload',
                'received' => [
                    'has_endpoint' => (bool) $endpoint,
                    'has_p256dh' => (bool) $p256dh,
                    'has_auth' => (bool) $auth,
                ],
            ], 400);
        }

        try {
            $sub = $repo->findByEndpoint($endpoint) ?? new PushSubscription();
            $sub->setUser($this->getUser())
                ->setEndpoint($endpoint)
                ->setP256dh($p256dh)
                ->setAuth($auth);

            $em->persist($sub);
            $em->flush();
        } catch (\Throwable $e) {
            $logger->error('push.subscribe.failure', [
                'message' => $e->getMessage(),
                'class' => $e::class,
            ]);
            return $this->json([
                'status' => 'error',
                'message' => 'DB write failed: ' . $e->getMessage(),
            ], 500);
        }

        return $this->json([
            'status' => 'success',
            'id'     => (string) $sub->getId(),
        ]);
    }
}
