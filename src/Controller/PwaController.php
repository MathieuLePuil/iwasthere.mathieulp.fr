<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Asset\Packages;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class PwaController extends AbstractController
{
    /**
     * Page de repli servie par le service worker quand une page demandée n'est ni
     * accessible (hors-ligne) ni déjà en cache. Volontairement publique et autonome
     * (styles en ligne) : le SW la précache à l'installation, et elle doit s'afficher
     * même si app.css n'a pas encore été mis en cache.
     */
    #[Route('/offline', name: 'app_offline')]
    public function offline(): Response
    {
        return $this->render('pwa/offline.html.twig');
    }

    #[Route('/manifest.json', name: 'app_manifest')]
    public function manifest(Packages $assets): JsonResponse
    {
        $icon = fn(string $file, string $sizes, string $purpose = 'any') => [
            'src' => $assets->getUrl('images/icons/' . $file),
            'sizes' => $sizes,
            'type' => 'image/png',
            'purpose' => $purpose,
        ];

        $data = [
            'name' => 'IWasThere',
            'short_name' => 'IWasThere',
            'description' => 'Ton journal d\'expériences live',
            'start_url' => '/',
            'scope' => '/',
            'display' => 'standalone',
            'background_color' => '#0B0D10',
            'theme_color' => '#B060FF',
            'orientation' => 'portrait',
            'lang' => 'fr',
            'icons' => [
                $icon('apple-touch-icon.png', '180x180'),
                $icon('icon-48.png', '48x48'),
                $icon('icon-72.png', '72x72'),
                $icon('icon-96.png', '96x96'),
                $icon('icon-128.png', '128x128'),
                $icon('icon-144.png', '144x144'),
                $icon('icon-152.png', '152x152'),
                $icon('icon-192.png', '192x192'),
                $icon('icon-384.png', '384x384'),
                $icon('icon-512.png', '512x512'),
                $icon('icon-512.png', '512x512', 'maskable'),
            ],
        ];

        $response = new JsonResponse($data);
        $response->headers->set('Content-Type', 'application/manifest+json');

        return $response;
    }
}
