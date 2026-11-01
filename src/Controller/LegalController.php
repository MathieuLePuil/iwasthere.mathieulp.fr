<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Pages légales : accessibles à tous, sans authentification, pour rester
 * consultables même déconnecté (obligation d'accessibilité des mentions légales).
 */
class LegalController extends AbstractController
{
    #[Route('/mentions-legales', name: 'app_legal_notice')]
    public function notice(): Response
    {
        return $this->render('legal/notice.html.twig');
    }

    #[Route('/confidentialite', name: 'app_privacy')]
    public function privacy(): Response
    {
        return $this->render('legal/privacy.html.twig');
    }
}
