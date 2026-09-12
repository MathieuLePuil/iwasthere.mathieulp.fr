<?php

namespace App\Security;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\AvatarService;
use Doctrine\ORM\EntityManagerInterface;
use KnpU\OAuth2ClientBundle\Client\ClientRegistry;
use Psr\Log\LoggerInterface;
use KnpU\OAuth2ClientBundle\Security\Authenticator\OAuth2Authenticator;
use League\OAuth2\Client\Provider\GoogleUser;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
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

    /** Passe à true quand ce login vient de créer le compte, pour router vers l'onboarding. */
    private bool $isNewUser = false;

    public function __construct(
        private ClientRegistry $clientRegistry,
        private EntityManagerInterface $em,
        private RouterInterface $router,
        private UserRepository $userRepository,
        private AvatarService $avatarService,
        private LoggerInterface $logger,
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
                $this->isNewUser = true;
                $user = new User();
                $user->setGoogleId($googleUser->getId());
                $user->setEmail($googleUser->getEmail());
                $user->setDisplayName(mb_substr($googleUser->getName() ?? $googleUser->getEmail(), 0, 100));
                // Même alphabet et même longueur que l'inscription classique (et que la
                // route /p/{pseudo}) : un pseudo vide ou trop long n'aurait pas de page.
                $baseUsername = substr(strtolower(preg_replace('/[^a-zA-Z0-9]/', '', explode('@', $googleUser->getEmail())[0]) ?? ''), 0, 24);
                if (strlen($baseUsername) < 3) {
                    $baseUsername = 'user';
                }
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
        // Une photo déjà en place (téléversée ou déjà synchronisée) n'est pas écrasée
        if ($user->getAvatarUrl()) {
            return;
        }

        $remoteUrl = $googleUser->getAvatar();
        if (!$remoteUrl) {
            return;
        }

        $localPath = $this->avatarService->downloadFromUrl($remoteUrl, $user);
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

        // Compte tout juste créé via Google : même mini-parcours que l'inscription
        // classique, plutôt qu'un accueil vide.
        if ($this->isNewUser) {
            return new RedirectResponse($this->router->generate('app_onboarding'));
        }

        return new RedirectResponse($this->router->generate('app_home'));
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        // Le détail (réponse Google, réseau) va dans le journal ; l'utilisateur voit
        // un message qu'il peut suivre, dans le flash que le layout affiche déjà —
        // l'ancien `oauth_error` en session n'était lu par aucun template.
        $this->logger->warning('Connexion Google refusée', ['exception' => $exception]);

        $session = $request->getSession();
        if ($session instanceof FlashBagAwareSessionInterface) {
            $session->getFlashBag()->add('error', 'La connexion avec Google n\'a pas abouti. Réessaie, ou connecte-toi avec ton email.');
        }

        return new RedirectResponse($this->router->generate('app_login'));
    }
}
