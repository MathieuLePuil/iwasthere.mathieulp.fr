<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\EventParticipationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
#[Route('/sport')]
class SportController extends AbstractController
{
    /** Une page à la fois ; les compteurs d'onglets viennent de COUNT, pas de listes chargées pour rien. */
    private const PER_PAGE = 30;

    #[Route('', name: 'app_sport')]
    public function index(Request $request, EventParticipationRepository $repo): Response
    {
        $user = $this->getUser();
        $tab = $request->query->get('tab', 'past') === 'upcoming' ? 'upcoming' : 'past';
        $filterType = (string) $request->query->get('type', '');
        $filterYear = preg_match('/^\d{4}$/', (string) $request->query->get('year', '')) ? (string) $request->query->get('year') : '';
        $sortBy = (string) $request->query->get('sort', 'date');

        $pastCount = $repo->countHistory($user, 'past', $filterType, $filterYear, 'sport');
        $upcomingCount = $repo->countHistory($user, 'upcoming', $filterType, '', 'sport');
        $total = $tab === 'upcoming' ? $upcomingCount : $pastCount;
        $totalPages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min(max(1, $request->query->getInt('page', 1)), $totalPages);

        return $this->render('sport/index.html.twig', [
            'participations' => $repo->findHistoryPage($user, $tab, $filterType, $filterYear, $page, self::PER_PAGE, $sortBy, 'sport'),
            'past_count'     => $pastCount,
            'upcoming_count' => $upcomingCount,
            'tab'            => $tab,
            'filter_type'    => $filterType,
            'filter_year'    => $filterYear,
            'sort_by'        => $sortBy,
            'years'          => $repo->findHistoryYears($user, 'sport'),
            'page'           => $page,
            'total_pages'    => $totalPages,
            'total'          => $total,
        ]);
    }

    /** Équipes porte-bonheur : une par sport collectif. Le setter filtre et vide. */
    #[Route('/favorite-team', name: 'app_sport_favorite_team', methods: ['POST'])]
    public function favoriteTeam(Request $request, EntityManagerInterface $em): Response
    {
        $this->getUser()->setFavoriteTeams($request->request->all('favorite_team'));
        $em->flush();

        $this->addFlash('success', 'Tes équipes porte-bonheur sont enregistrées.');

        return $this->redirectToRoute('app_sport');
    }
}
