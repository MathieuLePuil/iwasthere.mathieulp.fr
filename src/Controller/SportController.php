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
#[Route('/sport')]
class SportController extends AbstractController
{
    #[Route('', name: 'app_sport')]
    public function index(Request $request, EventParticipationRepository $repo): Response
    {
        $user = $this->getUser();
        $tab = $request->query->get('tab', 'past');
        $filterSport = $request->query->get('sport', '');
        $filterYear = $request->query->get('year', '');

        $pastParticipations = $repo->findSportPast($user, $filterSport, $filterYear);
        $upcomingParticipations = $repo->findSportUpcoming($user, $filterSport);
        $participations = $tab === 'upcoming' ? $upcomingParticipations : $pastParticipations;

        $years = $repo->findSportYears($user);

        return $this->render('sport/index.html.twig', [
            'participations' => $participations,
            'tab' => $tab,
            'filter_sport' => $filterSport,
            'filter_year' => $filterYear,
            'years' => $years,
            'past_count' => count($pastParticipations),
            'upcoming_count' => count($upcomingParticipations),
        ]);
    }
}
