<?php

namespace App\Security;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\AvatarService;
use Doctrine\ORM\EntityManagerInterface;
use KnpU\OAuth2ClientBundle\Client\ClientRegistry;
use KnpU\OAuth2ClientBundle\Security\Authenticator\OAuth2Authenticator;
use League\OAuth2\Client\Provider\GoogleUser;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\RememberMeBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Security\Http\Util\TargetPathTrait;

class GoogleAuthenticator extends OAuth2Authenticator
{
    use TargetPathTrait;
    public function __construct(
        private ClientRegistry $clientRegistry,
        private EntityManagerInterface $em,
        private RouterInterface $router,
        private UserRepository $userRepository,
        private AvatarService $avatarService,
    ) {}

    public function supports(Request $request): ?bool
    {
        return $request->attributes->get('_route') === 'app_google_callback';
    }

    public function authenticate(Request $request): SelfValidatingPassport
    {
        $client = $this->clientRegistry->getClient('google');
        $accessToken = $this->fetchAccessToken($client);

        return new SelfValidatingPassport(
            new UserBadge($accessToken->getToken(), function () use ($accessToken, $client): User {
                /** @var GoogleUser $googleUser */
                $googleUser = $client->fetchUserFromToken($accessToken);

                // Try to find existing user by Google ID
                $existingUser = $this->userRepository->findOneBy(['googleId' => $googleUser->getId()]);
                if ($existingUser) {
                    $this->syncAvatar($existingUser, $googleUser);
                    $this->em->flush();
                    return $existingUser;
                }

                // Try to find by email
                $existingUser = $this->userRepository->findOneBy(['email' => $googleUser->getEmail()]);
                if ($existingUser) {
                    $existingUser->setGoogleId($googleUser->getId());
                    $this->syncAvatar($existingUser, $googleUser);
                    $this->em->flush();
                    return $existingUser;
                }

                // Create new user
                $user = new User();
                $user->setGoogleId($googleUser->getId());
                $user->setEmail($googleUser->getEmail());
                $user->setDisplayName($googleUser->getName() ?? $googleUser->getEmail());
                $baseUsername = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', explode('@', $googleUser->getEmail())[0]));
                $user->setUsername($this->generateUniqueUsername($baseUsername));

                $this->em->persist($user);
                $this->em->flush();

                // Download avatar after persist so we have the user ID
                $this->syncAvatar($user, $googleUser);
                $this->em->flush();

                return $user;
            }),
            [new RememberMeBadge()]
        );
    }

    private function syncAvatar(User $user, GoogleUser $googleUser): void
    {
        // Don't overwrite a manually uploaded avatar
        if ($user->getAvatarUrl() && str_starts_with($user->getAvatarUrl(), '/uploads/avatars/')) {
            return;
        }

        $remoteUrl = $googleUser->getAvatar();
        if (!$remoteUrl) {
            return;
        }

        $localPath = $this->avatarService->downloadFromUrl($remoteUrl, (string) $user->getId());
        if ($localPath) {
            $user->setAvatarUrl($localPath);
        }
    }

    private function generateUniqueUsername(string $base): string
    {
        $username = $base;
        $counter = 1;
        while ($this->userRepository->findOneBy(['username' => $username])) {
            $username = $base . $counter;
            $counter++;
        }
        return $username;
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        if ($targetPath = $this->getTargetPath($request->getSession(), $firewallName)) {
            $this->removeTargetPath($request->getSession(), $firewallName);
            return new RedirectResponse($targetPath);
        }

        return new RedirectResponse($this->router->generate('app_home'));
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        $request->getSession()->set('oauth_error', $exception->getMessage());
        return new RedirectResponse($this->router->generate('app_login'));
    }
}
