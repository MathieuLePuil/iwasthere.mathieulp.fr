<?php

declare(strict_types=1);

namespace App\Controller;

use App\Rewind\RewindService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
class RewindController extends AbstractController
{
    #[Route('/rewind', name: 'app_rewind')]
    public function index(RewindService $rewind): Response
    {
        $user = $this->getUser();

        // La fenêtre d'un mois fait foi : passé ce délai la page se referme,
        // même si le lien a été gardé quelque part
        if (!$user->isRewindAvailable()) {
            $this->addFlash('info', 'Ton Rewind n\'est pas disponible pour le moment.');

            return $this->redirectToRoute('app_home');
        }

        $data = $rewind->build($user, $user->getRewindYear());
        if ($data === null) {
            $this->addFlash('info', 'Pas assez d\'événements pour un Rewind cette année.');

            return $this->redirectToRoute('app_home');
        }

        return $this->render('rewind/index.html.twig', [
            'rewind' => $data,
            'expires_at' => $user->getRewindExpiresAt(),
        ]);
    }
}
