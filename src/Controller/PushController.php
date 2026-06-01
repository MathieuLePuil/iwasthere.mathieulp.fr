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
class PushController extends AbstractController
{
    #[Route('/push/subscribe', name: 'app_push_subscribe', methods: ['POST'])]
    public function subscribe(
        Request $request,
        EntityManagerInterface $em,
        PushSubscriptionRepository $repo,
    ): JsonResponse {
        $data = json_decode($request->getContent(), true);

        $endpoint = $data['endpoint'] ?? null;
        $p256dh = $data['keys']['p256dh'] ?? null;
        $auth = $data['keys']['auth'] ?? null;

        if (!$endpoint || !$p256dh || !$auth) {
            return $this->json(['status' => 'error', 'message' => 'Invalid subscription'], 400);
        }

        $sub = $repo->findByEndpoint($endpoint) ?? new PushSubscription();
        $sub->setUser($this->getUser())
            ->setEndpoint($endpoint)
            ->setP256dh($p256dh)
            ->setAuth($auth);

        $em->persist($sub);
        $em->flush();

        return $this->json(['status' => 'success']);
    }
}
