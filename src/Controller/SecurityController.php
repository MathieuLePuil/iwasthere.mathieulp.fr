<?php

namespace App\Controller;

use App\Entity\User;
use App\Form\RegistrationFormType;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use KnpU\OAuth2ClientBundle\Client\ClientRegistry;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;
use Symfony\Component\Security\Http\Util\TargetPathTrait;

class SecurityController extends AbstractController
{
    use TargetPathTrait;

    #[Route('/login', name: 'app_login')]
    public function login(AuthenticationUtils $authenticationUtils): Response
    {
        if ($this->getUser()) {
            return $this->redirectToRoute('app_home');
        }

        return $this->render('security/login.html.twig', [
            'error' => $authenticationUtils->getLastAuthenticationError(),
            'last_username' => $authenticationUtils->getLastUsername(),
        ]);
    }

    #[Route('/logout', name: 'app_logout')]
    public function logout(): void
    {
        throw new \LogicException('This method can be blank - it will be intercepted by the logout key on your firewall.');
    }

    #[Route('/register', name: 'app_register')]
    public function register(
        Request $request,
        UserPasswordHasherInterface $passwordHasher,
        EntityManagerInterface $em,
    ): Response {
        if ($this->getUser()) {
            return $this->redirectToRoute('app_home');
        }

        $user = new User();
        $form = $this->createForm(RegistrationFormType::class, $user);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $hashedPassword = $passwordHasher->hashPassword($user, $form->get('plainPassword')->getData());
            $user->setPassword($hashedPassword);
            $em->persist($user);
            $em->flush();

            $this->addFlash('success', 'Compte créé avec succès ! Bienvenue sur IWasThere.');
            return $this->redirectToRoute('app_home');
        }

        return $this->render('security/register.html.twig', [
            'form' => $form,
        ]);
    }

    #[Route('/register/check-username', name: 'app_register_check_username')]
    public function checkUsername(Request $request, UserRepository $userRepo): JsonResponse
    {
        $username = trim($request->query->get('username', ''));
        if (strlen($username) < 3) {
            return $this->json(['available' => null]);
        }
        $taken = $userRepo->findOneBy(['username' => $username]) !== null;
        return $this->json(['available' => !$taken]);
    }

    #[Route('/forgot-password', name: 'app_forgot_password')]
    public function forgotPassword(
        Request $request,
        UserRepository $userRepo,
        EntityManagerInterface $em,
        MailerInterface $mailer,
    ): Response {
        if ($this->getUser()) {
            return $this->redirectToRoute('app_home');
        }

        if ($request->isMethod('POST')) {
            $emailAddress = trim((string) $request->request->get('email', ''));
            $user = $userRepo->findOneByEmail($emailAddress);

            if ($user) {
                $token = bin2hex(random_bytes(32));
                $user->setPasswordResetToken($token)
                    ->setPasswordResetTokenExpiresAt(new \DateTimeImmutable('+1 hour'));
                $em->flush();

                $resetUrl = $this->generateUrl(
                    'app_reset_password',
                    ['token' => $token],
                    UrlGeneratorInterface::ABSOLUTE_URL
                );

                $html = $this->renderView('emails/reset_password.html.twig', [
                    'user' => $user,
                    'reset_url' => $resetUrl,
                ]);

                $email = (new Email())
                    ->from(new Address('noreply@mathieulp.fr', 'IWasThere'))
                    ->to($emailAddress)
                    ->subject('Réinitialisation de ton mot de passe — IWasThere')
                    ->html($html);

                try {
                    $mailer->send($email);
                } catch (\Throwable) {
                    // silently ignore mail errors in dev
                }
            }

            // Always show the same message to prevent email enumeration
            $this->addFlash('success', 'Si un compte existe avec cette adresse, tu recevras un email dans quelques instants.');
            return $this->redirectToRoute('app_forgot_password');
        }

        return $this->render('security/forgot_password.html.twig');
    }

    #[Route('/reset-password/{token}', name: 'app_reset_password')]
    public function resetPassword(
        string $token,
        Request $request,
        UserRepository $userRepo,
        EntityManagerInterface $em,
        UserPasswordHasherInterface $hasher,
    ): Response {
        if ($this->getUser()) {
            return $this->redirectToRoute('app_home');
        }

        $user = $userRepo->findOneBy(['passwordResetToken' => $token]);

        if (!$user || !$user->isPasswordResetTokenValid()) {
            $this->addFlash('error', 'Ce lien est invalide ou a expiré. Redemande une réinitialisation.');
            return $this->redirectToRoute('app_forgot_password');
        }

        if ($request->isMethod('POST')) {
            $newPassword = $request->request->get('new_password', '');
            $confirm = $request->request->get('confirm_password', '');

            if (strlen($newPassword) < 8) {
                $this->addFlash('error', 'Le mot de passe doit faire au moins 8 caractères.');
                return $this->render('security/reset_password.html.twig', ['token' => $token]);
            }

            if ($newPassword !== $confirm) {
                $this->addFlash('error', 'Les mots de passe ne correspondent pas.');
                return $this->render('security/reset_password.html.twig', ['token' => $token]);
            }

            $user->setPassword($hasher->hashPassword($user, $newPassword))
                ->setPasswordResetToken(null)
                ->setPasswordResetTokenExpiresAt(null);
            $em->flush();

            $this->addFlash('success', 'Mot de passe mis à jour ! Tu peux maintenant te connecter.');
            return $this->redirectToRoute('app_login');
        }

        return $this->render('security/reset_password.html.twig', ['token' => $token]);
    }

    #[Route('/oauth/google', name: 'app_google_connect')]
    public function connectGoogle(ClientRegistry $clientRegistry): Response
    {
        return $clientRegistry->getClient('google')->redirect(['email', 'profile'], []);
    }

    #[Route('/oauth/google/callback', name: 'app_google_callback')]
    public function googleCallback(): Response
    {
        // Handled by GoogleAuthenticator
        return $this->redirectToRoute('app_home');
    }

    #[Route('/oauth/google/refresh-avatar', name: 'app_google_refresh_avatar')]
    public function refreshGoogleAvatar(Request $request, ClientRegistry $clientRegistry): Response
    {
        $this->saveTargetPath($request->getSession(), 'main', $this->generateUrl('app_settings'));
        return $clientRegistry->getClient('google')->redirect(['email', 'profile'], []);
    }
}
