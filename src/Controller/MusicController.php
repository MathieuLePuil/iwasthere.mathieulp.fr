<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\EventParticipationRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
#[Route('/music')]
class MusicController extends AbstractController
{
    /** Une page à la fois ; les compteurs d'onglets viennent de COUNT, pas de listes chargées pour rien. */
    private const PER_PAGE = 30;

    #[Route('', name: 'app_music')]
    public function index(Request $request, EventParticipationRepository $repo): Response
    {
        $user = $this->getUser();
        $tab = $request->query->get('tab', 'past') === 'upcoming' ? 'upcoming' : 'past';
        $filterType = (string) $request->query->get('type', '');
        $filterYear = preg_match('/^\d{4}$/', (string) $request->query->get('year', '')) ? (string) $request->query->get('year') : '';
        $sortBy = (string) $request->query->get('sort', 'date');

        $pastCount = $repo->countHistory($user, 'past', $filterType, $filterYear, 'music');
        $upcomingCount = $repo->countHistory($user, 'upcoming', $filterType, '', 'music');
        $total = $tab === 'upcoming' ? $upcomingCount : $pastCount;
        $totalPages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min(max(1, $request->query->getInt('page', 1)), $totalPages);

        return $this->render('music/index.html.twig', [
            'participations' => $repo->findHistoryPage($user, $tab, $filterType, $filterYear, $page, self::PER_PAGE, $sortBy, 'music'),
            'past_count'     => $pastCount,
            'upcoming_count' => $upcomingCount,
            'tab'            => $tab,
            'filter_type'    => $filterType,
            'filter_year'    => $filterYear,
            'sort_by'        => $sortBy,
            'years'          => $repo->findHistoryYears($user, 'music'),
            'page'           => $page,
            'total_pages'    => $totalPages,
            'total'          => $total,
        ]);
    }
}
