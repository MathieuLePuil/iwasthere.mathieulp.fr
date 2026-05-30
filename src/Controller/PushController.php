<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\PushSubscription;
use App\Repository\PushSubscriptionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
#[Route('/push')]
class PushController extends AbstractController
{
    public function __construct(private readonly string $vapidPublicKey) {}

    #[Route('/subscribe', name: 'app_push_subscribe', methods: ['POST'])]
    public function subscribe(Request $request, EntityManagerInterface $em, PushSubscriptionRepository $repo): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        $endpoint = $data['endpoint'] ?? '';
        $p256dh = $data['keys']['p256dh'] ?? '';
        $auth = $data['keys']['auth'] ?? '';

        if (!$endpoint || !$p256dh || !$auth) {
            return $this->json(['error' => 'Invalid subscription data'], 400);
        }

        $existing = $repo->findByEndpoint($endpoint);
        if (!$existing) {
            $sub = new PushSubscription();
            $sub->setUser($this->getUser())
                ->setEndpoint($endpoint)
                ->setP256dh($p256dh)
                ->setAuth($auth);
            $em->persist($sub);
            $em->flush();
        }

        return $this->json(['success' => true]);
    }

    #[Route('/unsubscribe', name: 'app_push_unsubscribe', methods: ['POST'])]
    public function unsubscribe(Request $request, EntityManagerInterface $em, PushSubscriptionRepository $repo): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $endpoint = $data['endpoint'] ?? '';

        if ($endpoint) {
            $sub = $repo->findByEndpoint($endpoint);
            if ($sub && $sub->getUser() === $this->getUser()) {
                $em->remove($sub);
                $em->flush();
            }
        }

        return $this->json(['success' => true]);
    }
}
