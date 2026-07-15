<?php

declare(strict_types=1);

namespace App\Controller;

use App\Notification\NotificationType;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

#[IsGranted('ROLE_USER')]
#[Route('/settings')]
class SettingsController extends AbstractController
{
    public function __construct(
        private readonly TokenStorageInterface $tokenStorage,
    ) {}

    #[Route('', name: 'app_settings')]
    public function index(): Response
    {
        return $this->render('settings/index.html.twig', [
            'notif_groups' => NotificationType::groups(),
        ]);
    }

    #[Route('/check-username', name: 'app_settings_check_username', methods: ['GET'])]
    public function checkUsername(Request $request, UserRepository $userRepo): JsonResponse
    {
        $username = strtolower(trim($request->query->get('username', '')));
        if (strlen($username) < 3) {
            return $this->json(['available' => null]);
        }
        if ($username === $this->getUser()->getUserIdentifier()) {
            return $this->json(['available' => 'current']);
        }
        $taken = $userRepo->findOneBy(['username' => $username]) !== null;
        return $this->json(['available' => !$taken]);
    }

    #[Route('/notifications', name: 'app_settings_notifications', methods: ['POST'])]
    public function saveNotifications(Request $request, EntityManagerInterface $em): Response
    {
        $user = $this->getUser();

        // Une case non cochée n'est pas postée : on part du catalogue et non de
        // la requête, sinon un type absent du formulaire serait réactivé en douce
        $prefs = [];
        foreach (NotificationType::cases() as $type) {
            $prefs[$type->value] = $request->request->getBoolean('notif_' . $type->value);
        }

        $user->setNotifPrefs($prefs)
            ->setNotifCompletionTime($request->request->get('notif_completion_time', '08:00'));
        $em->flush();

        $this->addFlash('success', 'Préférences de notifications sauvegardées.');
        return $this->redirectToRoute('app_settings');
    }

    #[Route('/privacy', name: 'app_settings_privacy', methods: ['POST'])]
    public function savePrivacy(Request $request, EntityManagerInterface $em): Response
    {
        $user = $this->getUser();
        $user->setProfileVisibility($request->request->get('profile_visibility', 'friends'))
            ->setDefaultEventVisibility($request->request->get('default_event_visibility', 'friends'));
        $em->flush();
        $this->addFlash('success', 'Paramètres de confidentialité sauvegardés.');
        return $this->redirectToRoute('app_settings');
    }

    #[Route('/profile', name: 'app_settings_profile', methods: ['POST'])]
    public function saveProfile(Request $request, EntityManagerInterface $em, UserRepository $userRepo): Response
    {
        $user = $this->getUser();

        $displayName = trim($request->request->get('display_name', ''));
        if ($displayName) {
            $user->setDisplayName($displayName);
        }

        $bio = trim($request->request->get('bio', ''));
        $user->setBio($bio ?: null);

        $newUsername = strtolower(trim($request->request->get('username', '')));
        if ($newUsername && $newUsername !== $user->getUserIdentifier()) {
            if (!preg_match('/^[a-z0-9_]{3,30}$/', $newUsername)) {
                $this->addFlash('error', 'Le pseudo doit faire 3 à 30 caractères (lettres minuscules, chiffres, _).');
                return $this->redirectToRoute('app_settings');
            }
            $existing = $userRepo->findOneBy(['username' => $newUsername]);
            if ($existing !== null) {
                $this->addFlash('error', 'Ce pseudo est déjà utilisé.');
                return $this->redirectToRoute('app_settings');
            }
            $user->setUsername($newUsername);
        }

        $em->flush();
        $this->addFlash('success', 'Profil mis à jour.');
        return $this->redirectToRoute('app_settings');
    }

    #[Route('/password', name: 'app_settings_password', methods: ['POST'])]
    public function changePassword(
        Request $request,
        EntityManagerInterface $em,
        UserPasswordHasherInterface $hasher
    ): Response {
        $user = $this->getUser();
        $current = $request->request->get('current_password');
        $new = $request->request->get('new_password');
        $confirm = $request->request->get('confirm_password');

        if (!$user->getPassword() || !$hasher->isPasswordValid($user, $current)) {
            $this->addFlash('error', 'Mot de passe actuel incorrect.');
            return $this->redirectToRoute('app_settings');
        }
        if ($new !== $confirm) {
            $this->addFlash('error', 'Les nouveaux mots de passe ne correspondent pas.');
            return $this->redirectToRoute('app_settings');
        }
        if (strlen($new) < 8) {
            $this->addFlash('error', 'Le mot de passe doit faire au moins 8 caractères.');
            return $this->redirectToRoute('app_settings');
        }

        $user->setPassword($hasher->hashPassword($user, $new));
        $em->flush();
        $this->addFlash('success', 'Mot de passe modifié avec succès.');
        return $this->redirectToRoute('app_settings');
    }

    #[Route('/delete', name: 'app_settings_delete_account', methods: ['POST'])]
    public function deleteAccount(Request $request, EntityManagerInterface $em): Response
    {
        $user = $this->getUser();
        $confirmation = $request->request->get('confirmation');
        if ($confirmation !== 'SUPPRIMER') {
            $this->addFlash('error', 'Confirmation incorrecte. Tape exactement SUPPRIMER.');
            return $this->redirectToRoute('app_settings');
        }

        $this->tokenStorage->setToken(null);
        $em->remove($user);
        $em->flush();

        return $this->redirectToRoute('app_login');
    }
}
