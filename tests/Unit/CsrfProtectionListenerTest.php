<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\EventListener\CsrfProtectionListener;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

final class CsrfProtectionListenerTest extends TestCase
{
    private function listener(bool $valid): CsrfProtectionListener
    {
        $manager = $this->createStub(CsrfTokenManagerInterface::class);
        $manager->method('isTokenValid')->willReturnCallback(
            fn (CsrfToken $token) => $valid && $token->getId() === CsrfProtectionListener::TOKEN_ID,
        );

        return new CsrfProtectionListener($manager);
    }

    private function event(Request $request): RequestEvent
    {
        return new RequestEvent($this->createStub(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST);
    }

    public function testSafeMethodsPass(): void
    {
        $request = Request::create('/event/x', 'GET');
        $request->attributes->set('_route', 'app_event_show');

        $this->listener(false)($this->event($request));
        $this->addToAssertionCount(1);
    }

    public function testPostWithoutTokenIsRefused(): void
    {
        $request = Request::create('/settings/delete', 'POST');
        $request->attributes->set('_route', 'app_settings_delete_account');

        $this->expectException(AccessDeniedHttpException::class);
        $this->listener(false)($this->event($request));
    }

    public function testTokenInFieldOrHeaderIsAccepted(): void
    {
        $listener = $this->listener(true);

        $request = Request::create('/settings/delete', 'POST', ['_token' => 'abc']);
        $request->attributes->set('_route', 'app_settings_delete_account');
        $listener($this->event($request));

        $request = Request::create('/subscribe', 'POST', server: ['HTTP_X_CSRF_TOKEN' => 'abc']);
        $request->attributes->set('_route', 'app_subscribe');
        $listener($this->event($request));

        $this->addToAssertionCount(2);
    }

    public function testLoginAndRegisterCheckTheirOwnToken(): void
    {
        $listener = $this->listener(false);
        foreach (['app_login', 'app_register', 'app_reaction_toggle'] as $route) {
            $request = Request::create('/x', 'POST');
            $request->attributes->set('_route', $route);
            $listener($this->event($request));
        }
        $this->addToAssertionCount(3);
    }
}
