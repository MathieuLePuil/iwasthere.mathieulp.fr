<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\EventParticipationRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
#[Route('/stats')]
class StatsController extends AbstractController
{
    #[Route('', name: 'app_stats')]
    public function index(EventParticipationRepository $repo): Response
    {
        $user = $this->getUser();
        $stats = $repo->computeStats($user);

        return $this->render('stats/index.html.twig', ['stats' => $stats]);
    }
}
