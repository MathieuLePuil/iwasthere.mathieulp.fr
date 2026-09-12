<?php

declare(strict_types=1);

namespace App\EventListener;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ResponseEvent;

/**
 * Les en-têtes de durcissement que ni Cloudflare ni l'hébergeur ne posent.
 *
 * Posés ici plutôt que dans la conf du serveur : ils suivent le code, et la
 * prod tourne derrière un proxy dont on ne maîtrise pas la configuration.
 * HSTS n'est envoyé qu'en HTTPS — sur localhost en HTTP il serait ignoré, et
 * sur un domaine de test il pourrait le verrouiller pour un an.
 */
#[AsEventListener(event: ResponseEvent::class)]
final class SecurityHeadersListener
{
    public function __invoke(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $headers = $event->getResponse()->headers;
        $request = $event->getRequest();

        if ($request->isSecure()) {
            $headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }
        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('X-Frame-Options', 'SAMEORIGIN');
        // L'URL complète ne quitte pas le site : les pages portent des ids d'événements
        // et de profils qu'un tiers (police, API) n'a pas à connaître.
        $headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        // Ce que l'app n'utilise pas est coupé, y compris pour les scripts tiers.
        $headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=(), usb=(), interest-cohort=()');

        // Ces pages ne doivent survivre ni dans le cache du navigateur ni dans celui
        // du service worker (qui lit ce même en-tête) : fil de notifications,
        // réglages, admin, écrans de connexion.
        foreach (self::NO_STORE_PREFIXES as $prefix) {
            $path = $request->getPathInfo();
            if ($path === $prefix || str_starts_with($path, $prefix . '/')) {
                $headers->set('Cache-Control', 'private, no-store');
                break;
            }
        }
    }

    private const NO_STORE_PREFIXES = ['/notifications', '/settings', '/admin', '/login', '/register', '/forgot-password', '/reset-password'];
}
