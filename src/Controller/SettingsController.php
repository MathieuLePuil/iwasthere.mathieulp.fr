<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Notification\NotificationType;
use App\Repository\UserRepository;
use App\Service\AccountDeletionService;
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

    /** Le sommaire : une simple liste de liens vers chaque rubrique de réglages. */
    #[Route('', name: 'app_settings')]
    public function index(): Response
    {
        return $this->render('settings/index.html.twig');
    }

    #[Route('/profil', name: 'app_settings_profile_page', methods: ['GET'])]
    public function profilePage(): Response
    {
        return $this->render('settings/profile.html.twig');
    }

    #[Route('/apparence', name: 'app_settings_appearance_page', methods: ['GET'])]
    public function appearancePage(): Response
    {
        return $this->render('settings/appearance.html.twig');
    }

    #[Route('/confidentialite', name: 'app_settings_privacy_page', methods: ['GET'])]
    public function privacyPage(): Response
    {
        return $this->render('settings/privacy.html.twig');
    }

    // Même URL que la sauvegarde POST plus bas : Symfony les distingue par la méthode.
    #[Route('/notifications', name: 'app_settings_notifications_page', methods: ['GET'])]
    public function notificationsPage(): Response
    {
        return $this->render('settings/notifications.html.twig', [
            'notif_groups' => NotificationType::groups(),
        ]);
    }

    #[Route('/compte', name: 'app_settings_account_page', methods: ['GET'])]
    public function accountPage(): Response
    {
        return $this->render('settings/account.html.twig');
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
        return $this->redirectToRoute('app_settings_notifications_page');
    }

    /** Appelé en fetch par le contrôleur Stimulus : le thème est déjà appliqué côté client. */
    #[Route('/theme', name: 'app_settings_theme', methods: ['POST'])]
    public function saveTheme(Request $request, EntityManagerInterface $em): JsonResponse
    {
        $theme = $request->request->get('theme');
        if (!in_array($theme, ['dark', 'light', 'auto'], true)) {
            return $this->json(['ok' => false], Response::HTTP_BAD_REQUEST);
        }

        $this->getUser()->setTheme($theme);
        $em->flush();

        return $this->json(['ok' => true]);
    }

    /**
     * Une audience par catégorie (événements, stats, liste d'amis), chacune parmi
     * private | friends | public. Une catégorie absente du formulaire est laissée
     * telle quelle ; une valeur forgée fait échouer toute la sauvegarde.
     */
    #[Route('/privacy', name: 'app_settings_privacy', methods: ['POST'])]
    public function savePrivacy(Request $request, EntityManagerInterface $em): Response
    {
        $user = $this->getUser();

        foreach (User::PRIVACY_CATEGORIES as $category) {
            $level = $request->request->get('privacy_' . $category);
            if ($level === null) {
                continue;
            }
            if (!in_array($level, User::PRIVACY_LEVELS, true)) {
                $this->addFlash('error', 'Réglage de confidentialité invalide.');
                return $this->redirectToRoute('app_settings_privacy_page');
            }
            $user->setPrivacyLevel($category, $level);
        }

        $em->flush();

        $this->addFlash('success', 'Réglages de confidentialité sauvegardés.');
        return $this->redirectToRoute('app_settings_privacy_page');
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
                return $this->redirectToRoute('app_settings_profile_page');
            }
            $existing = $userRepo->findOneBy(['username' => $newUsername]);
            if ($existing !== null) {
                $this->addFlash('error', 'Ce pseudo est déjà utilisé.');
                return $this->redirectToRoute('app_settings_profile_page');
            }
            $user->setUsername($newUsername);
        }

        $em->flush();
        $this->addFlash('success', 'Profil mis à jour.');
        return $this->redirectToRoute('app_settings_profile_page');
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
            return $this->redirectToRoute('app_settings_account_page');
        }
        if ($new !== $confirm) {
            $this->addFlash('error', 'Les nouveaux mots de passe ne correspondent pas.');
            return $this->redirectToRoute('app_settings_account_page');
        }
        if (strlen($new) < 8) {
            $this->addFlash('error', 'Le mot de passe doit faire au moins 8 caractères.');
            return $this->redirectToRoute('app_settings_account_page');
        }

        $user->setPassword($hasher->hashPassword($user, $new));
        $em->flush();
        $this->addFlash('success', 'Mot de passe modifié avec succès.');
        return $this->redirectToRoute('app_settings_account_page');
    }

    #[Route('/delete', name: 'app_settings_delete_account', methods: ['POST'])]
    public function deleteAccount(Request $request, AccountDeletionService $accountDeletion): Response
    {
        $user = $this->getUser();
        $confirmation = $request->request->get('confirmation');
        if ($confirmation !== 'SUPPRIMER') {
            $this->addFlash('error', 'Confirmation incorrecte. Tape exactement SUPPRIMER.');
            return $this->redirectToRoute('app_settings_account_page');
        }

        // Déconnexion après la suppression : si elle échoue, le compte existe
        // toujours et l'utilisateur doit rester connecté pour le constater.
        $accountDeletion->delete($user);
        $this->tokenStorage->setToken(null);

        return $this->redirectToRoute('app_login');
    }
}
