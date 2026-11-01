<?php

declare(strict_types=1);

namespace App\EventListener;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;

/**
 * Les en-têtes de durcissement que ni Cloudflare ni l'hébergeur ne posent.
 *
 * Posés ici plutôt que dans la conf du serveur : ils suivent le code, et la
 * prod tourne derrière un proxy dont on ne maîtrise pas la configuration.
 * HSTS n'est envoyé qu'en HTTPS — sur localhost en HTTP il serait ignoré, et
 * sur un domaine de test il pourrait le verrouiller pour un an.
 *
 * La CSP n'admet aucun script inline : tout le JS vit dans assets/, et les deux
 * seuls scripts de tête (thème, installabilité) et l'importmap portent un nonce
 * tiré par requête (csp_nonce() dans Twig). Les styles inline restent admis :
 * les templates en portent un millier, et un style ne fait pas exécuter de code.
 */
#[AsEventListener(event: RequestEvent::class, method: 'onRequest', priority: 100)]
#[AsEventListener(event: ResponseEvent::class, method: 'onResponse')]
final class SecurityHeadersListener
{
    public const NONCE_ATTRIBUTE = 'csp_nonce';

    private const NO_STORE_PREFIXES = ['/notifications', '/settings', '/admin', '/login', '/register', '/forgot-password', '/reset-password'];

    public function onRequest(RequestEvent $event): void
    {
        if ($event->isMainRequest()) {
            $event->getRequest()->attributes->set(self::NONCE_ATTRIBUTE, base64_encode(random_bytes(16)));
        }
    }

    public function onResponse(ResponseEvent $event): void
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
        $headers->set('Content-Security-Policy', $this->csp($request));

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

    private function csp(Request $request): string
    {
        $nonce = (string) $request->attributes->get(self::NONCE_ATTRIBUTE, '');

        return implode('; ', [
            "default-src 'self'",
            // Le ticket souvenir dessine des blobs et des data: URI ; les photos d'artistes
            // et de profil sont servies d'ici, mais un avatar d'avant la synchronisation
            // Google pouvait rester distant.
            "img-src 'self' data: blob: https:",
            "script-src 'self' 'nonce-{$nonce}'",
            "style-src 'self' 'unsafe-inline'",
            "font-src 'self'",
            "connect-src 'self'",
            "worker-src 'self'",
            "manifest-src 'self'",
            "frame-ancestors 'self'",
            "base-uri 'self'",
            "form-action 'self'",
            "object-src 'none'",
        ]);
    }
}
