<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\EventParticipationRepository;
use App\Service\StatsDetailService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
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

    #[Route('/{topic}', name: 'app_stats_detail')]
    public function detail(string $topic, Request $request, StatsDetailService $details): Response
    {
        if (!in_array($topic, StatsDetailService::TOPICS, true)) {
            throw $this->createNotFoundException();
        }

        $data = $details->compute($this->getUser(), $topic, $request->query->all());
        if ($data === null) {
            return $this->redirectToRoute('app_stats');
        }

        return $this->render('stats/detail/' . $topic . '.html.twig', ['data' => $data]);
    }
}
