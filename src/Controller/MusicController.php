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
    #[Route('', name: 'app_music')]
    public function index(Request $request, EventParticipationRepository $repo): Response
    {
        $user = $this->getUser();
        $tab = $request->query->get('tab', 'past'); // past or upcoming
        $filterType = $request->query->get('type', ''); // concert, festival, ''
        $filterYear = $request->query->get('year', '');
        $sortBy = $request->query->get('sort', 'date'); // date, rating, duration

        $pastParticipations = $repo->findMusicPast($user, $filterType, $filterYear, $sortBy);
        $upcomingParticipations = $repo->findMusicUpcoming($user, $filterType);
        $participations = $tab === 'upcoming' ? $upcomingParticipations : $pastParticipations;

        $years = $repo->findMusicYears($user);

        return $this->render('music/index.html.twig', [
            'participations' => $participations,
            'past_count' => count($pastParticipations),
            'upcoming_count' => count($upcomingParticipations),
            'tab' => $tab,
            'filter_type' => $filterType,
            'filter_year' => $filterYear,
            'sort_by' => $sortBy,
            'years' => $years,
        ]);
    }
}
