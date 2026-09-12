<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\PushSubscription;
use App\Repository\PushSubscriptionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class PushController extends AbstractController
{
    /**
     * Enregistre l'abonnement push du navigateur (PushSubscription.toJSON()).
     * Un endpoint déjà connu est rattaché au compte courant : c'est le même
     * appareil qui a changé d'utilisateur.
     */
    #[IsGranted('ROLE_USER')]
    #[Route('/subscribe', name: 'app_subscribe', methods: ['POST'])]
    public function subscribe(Request $request, PushSubscriptionRepository $repo, EntityManagerInterface $em): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $endpoint = $data['endpoint'] ?? null;
        $p256dh = $data['keys']['p256dh'] ?? null;
        $auth = $data['keys']['auth'] ?? null;

        if (!is_string($endpoint) || !is_string($p256dh) || !is_string($auth)
            || strlen($endpoint) > 500 || strlen($p256dh) > 255 || strlen($auth) > 255
            || !str_starts_with($endpoint, 'https://')
            || !preg_match('/^[A-Za-z0-9_\-=]+$/', $p256dh) || !preg_match('/^[A-Za-z0-9_\-=]+$/', $auth)
        ) {
            return new JsonResponse(['status' => 'error'], Response::HTTP_BAD_REQUEST);
        }

        $subscription = $repo->findOneByEndpoint($endpoint);
        if ($subscription === null) {
            $em->persist(new PushSubscription($this->getUser(), $endpoint, $p256dh, $auth));
        } else {
            $subscription->setUser($this->getUser())->setKeys($p256dh, $auth);
        }
        $em->flush();

        return new JsonResponse(['status' => 'success']);
    }
}
