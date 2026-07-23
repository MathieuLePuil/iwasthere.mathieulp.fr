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
    #[Route('', name: 'app_sport')]
    public function index(Request $request, EventParticipationRepository $repo): Response
    {
        $user = $this->getUser();
        $tab = $request->query->get('tab', 'past');
        $filterType = $request->query->get('type', ''); // football, rugby, tennis, ''
        $filterYear = $request->query->get('year', '');
        $sortBy = $request->query->get('sort', 'date'); // date, rating

        $pastParticipations = $repo->findSportPast($user, $filterType, $filterYear, $sortBy);
        $upcomingParticipations = $repo->findSportUpcoming($user, $filterType);
        $participations = $tab === 'upcoming' ? $upcomingParticipations : $pastParticipations;

        $years = $repo->findSportYears($user);

        return $this->render('sport/index.html.twig', [
            'participations' => $participations,
            'tab' => $tab,
            'filter_type' => $filterType,
            'filter_year' => $filterYear,
            'sort_by' => $sortBy,
            'years' => $years,
            'past_count' => count($pastParticipations),
            'upcoming_count' => count($upcomingParticipations),
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
