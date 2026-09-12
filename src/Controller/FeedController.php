<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\FriendRepository;
use App\Service\FeedService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
class FeedController extends AppController
{
    /** Jours (= cartes) par chargement du scroll infini */
    private const DAYS_PER_PAGE = 8;

    public function __construct(
        private readonly FeedService $feedService,
        private readonly EntityManagerInterface $em,
    ) {}

    #[Route('/feed', name: 'app_feed')]
    public function index(FriendRepository $friendRepo): Response
    {
        $user = $this->user();
        $seenBefore = $user->getFeedLastSeenAt();

        $feed = $this->feedService->buildFeed($user, $seenBefore, 1, self::DAYS_PER_PAGE);

        // Mémorise la visite maintenant ; les chargements suivants du scroll
        // infini gardent l'ancienne limite via `seen_epoch` transmis au JS
        $user->setFeedLastSeenAt(new \DateTimeImmutable());
        $this->em->flush();

        return $this->render('feed/index.html.twig', [
            'friend_count' => $feed['friend_count'],
            'upcoming' => $feed['upcoming'],
            'days' => $feed['days'],
            'sep_at' => $feed['sep_at'],
            'has_more' => $feed['has_more'],
            'seen_epoch' => $seenBefore?->getTimestamp() ?? 0,
            'pending_requests' => count($friendRepo->findPendingReceived($user)),
            'reactions' => $feed['reactions'],
        ]);
    }

    /** Fragment HTML des cartes suivantes, chargé par le contrôleur Stimulus `feed` */
    #[Route('/feed/items', name: 'app_feed_items')]
    public function items(Request $request): Response
    {
        $user = $this->user();
        $page = max(1, $request->query->getInt('page', 1));
        $epoch = $request->query->getInt('seen');
        $seenBefore = $epoch > 0 ? (new \DateTimeImmutable())->setTimestamp($epoch) : null;

        // Pas de bandeau « Bientôt » sur les pages suivantes : il est déjà affiché
        $feed = $this->feedService->buildFeed($user, $seenBefore, $page, self::DAYS_PER_PAGE, withUpcoming: false);

        $response = $this->render('feed/_page.html.twig', [
            'days' => $feed['days'],
            'sep_at' => $feed['sep_at'],
            'reactions' => $feed['reactions'],
        ]);
        $response->headers->set('X-Feed-Has-More', $feed['has_more'] ? '1' : '0');

        return $response;
    }
}
