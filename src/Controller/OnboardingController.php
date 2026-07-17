<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
class OnboardingController extends AbstractController
{
    /**
     * Mini-parcours d'accueil après inscription : plutôt que d'arriver sur un accueil
     * vide, le nouvel arrivant est guidé vers son premier souvenir. Page volontairement
     * accessible à tout moment (aucune garde « déjà vu ») — c'est un point de départ,
     * pas une étape verrouillée ; l'utilisateur peut la quitter par « Voir mon accueil ».
     */
    #[Route('/bienvenue', name: 'app_onboarding')]
    public function index(): Response
    {
        return $this->render('onboarding/index.html.twig');
    }
}
