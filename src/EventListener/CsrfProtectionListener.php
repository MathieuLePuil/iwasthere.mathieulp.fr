<?php

declare(strict_types=1);

namespace App\EventListener;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/**
 * Refuse toute requête qui écrit sans jeton CSRF valide.
 *
 * Un seul jeton pour toute l'app (id « app »), posé dans le layout et joint par
 * csrf.js à chaque formulaire et à chaque fetch qui écrit — dans le champ
 * `_token` ou l'en-tête `X-CSRF-Token`. Vérifier ici plutôt que dans chaque
 * action : c'est ainsi que quarante POST s'étaient retrouvés sans protection,
 * le cookie SameSite=Lax restant la seule barrière.
 *
 * Exemptés : le login et l'inscription, dont form_login et le composant Form
 * vérifient déjà leur propre jeton, et la réaction, qui a le sien depuis le début.
 */
#[AsEventListener(event: RequestEvent::class, priority: 6)]
final class CsrfProtectionListener
{
    public const TOKEN_ID = 'app';

    private const EXEMPT_ROUTES = ['app_login', 'app_register', 'app_reaction_toggle'];

    public function __construct(private readonly CsrfTokenManagerInterface $tokens) {}

    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        if ($request->isMethodSafe()) {
            return;
        }

        $route = (string) $request->attributes->get('_route', '');
        if ($route === '' || in_array($route, self::EXEMPT_ROUTES, true) || str_starts_with($route, '_')) {
            return;
        }

        if (!$this->tokens->isTokenValid(new CsrfToken(self::TOKEN_ID, $this->tokenFrom($request)))) {
            throw new AccessDeniedHttpException('Jeton de sécurité manquant ou expiré : recharge la page et réessaie.');
        }
    }

    private function tokenFrom(Request $request): string
    {
        $token = $request->request->get('_token') ?? $request->headers->get('X-CSRF-Token');

        return is_string($token) ? $token : '';
    }
}
