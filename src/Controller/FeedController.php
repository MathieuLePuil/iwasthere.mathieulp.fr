<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\FriendRepository;
use App\Service\FeedService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
class FeedController extends AbstractController
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
        $user = $this->getUser();
        $seenBefore = $user->getFeedLastSeenAt();

        $feed = $this->feedService->buildFeed($user, $seenBefore);
        [$days, $sepAt, $hasMore] = $this->paginate($feed['days'], 1);

        // Mémorise la visite maintenant ; les chargements suivants du scroll
        // infini gardent l'ancienne limite via `seen_epoch` transmis au JS
        $user->setFeedLastSeenAt(new \DateTimeImmutable());
        $this->em->flush();

        return $this->render('feed/index.html.twig', [
            'friend_count' => $feed['friend_count'],
            'upcoming' => $feed['upcoming'],
            'days' => $days,
            'sep_at' => $sepAt,
            'has_more' => $hasMore,
            'seen_epoch' => $seenBefore?->getTimestamp() ?? 0,
            'pending_requests' => count($friendRepo->findPendingReceived($user)),
            'reactions' => $feed['reactions'],
        ]);
    }

    /** Fragment HTML des cartes suivantes, chargé par le contrôleur Stimulus `feed` */
    #[Route('/feed/items', name: 'app_feed_items')]
    public function items(Request $request): Response
    {
        $user = $this->getUser();
        $page = max(1, $request->query->getInt('page', 1));
        $epoch = $request->query->getInt('seen');
        $seenBefore = $epoch > 0 ? (new \DateTimeImmutable())->setTimestamp($epoch) : null;

        $feed = $this->feedService->buildFeed($user, $seenBefore);
        [$days, $sepAt, $hasMore] = $this->paginate($feed['days'], $page);

        $response = $this->render('feed/_page.html.twig', [
            'days' => $days,
            'sep_at' => $sepAt,
            'reactions' => $feed['reactions'],
        ]);
        $response->headers->set('X-Feed-Has-More', $hasMore ? '1' : '0');

        return $response;
    }

    /**
     * Découpe la liste des jours pour une page et positionne la limite
     * « déjà vu » : juste avant le premier jour sans rien de nouveau
     * (null si rien de nouveau du tout, ou si la limite tombe hors page).
     * Les jours redevenus nouveaux sous la limite (ami qui ajoute un vieil
     * événement) reçoivent `badge_new`.
     *
     * @param list<array> $allDays
     * @return array{0: list<array>, 1: ?int, 2: bool}
     */
    private function paginate(array $allDays, int $page): array
    {
        $start = ($page - 1) * self::DAYS_PER_PAGE;
        $slice = array_slice($allDays, $start, self::DAYS_PER_PAGE);
        $hasMore = count($allDays) > $start + count($slice);

        $sepIndex = null;
        foreach ($allDays as $i => $d) {
            if (!$d['is_new']) {
                $sepIndex = $i;
                break;
            }
        }
        if ($sepIndex === 0) {
            $sepIndex = null; // rien de nouveau : pas de ligne en tête de feed
        }

        $sepAt = $sepIndex !== null && $sepIndex >= $start && $sepIndex < $start + count($slice)
            ? $sepIndex - $start
            : null;

        foreach ($slice as $j => &$d) {
            $d['badge_new'] = $sepIndex !== null && $d['is_new'] && ($start + $j) > $sepIndex;
        }
        unset($d);

        return [$slice, $sepAt, $hasMore];
    }
}
