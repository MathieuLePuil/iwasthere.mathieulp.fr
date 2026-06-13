<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Friend;
use App\Entity\Notification;
use App\Entity\User;
use App\Repository\EventParticipationRepository;
use App\Repository\FriendRepository;
use App\Repository\UserRepository;
use App\Service\AvatarService;
use App\Service\NotificationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
#[Route('/profile')]
class ProfileController extends AbstractController
{
    #[Route('', name: 'app_profile')]
    public function index(Request $request, FriendRepository $friendRepo, EventParticipationRepository $partRepo): Response
    {
        $user = $this->getUser();
        $friends = $friendRepo->findConfirmedFriends($user);
        $pendingRequests = $friendRepo->findPendingReceived($user);
        $sentRequests = $friendRepo->findPendingSent($user);
        $totalEvents = $partRepo->countByUser($user);
        $avgRating = $partRepo->getAvgRating($user);

        return $this->render('profile/index.html.twig', [
            'friends' => $friends,
            'pending_requests' => $pendingRequests,
            'sent_requests' => $sentRequests,
            'total_events' => $totalEvents,
            'avg_rating' => $avgRating,
            'tab' => $request->query->get('tab', 'profil'),
        ]);
    }

    #[Route('/avatar', name: 'app_profile_avatar', methods: ['POST'])]
    public function avatar(Request $request, AvatarService $avatarService, EntityManagerInterface $em): Response
    {
        $user = $this->getUser();
        $file = $request->files->get('avatar');

        if (!$file) {
            $this->addFlash('error', 'Aucun fichier reçu.');
            return $this->redirectToRoute('app_profile');
        }

        $path = $avatarService->saveUploadedFile($file, (string) $user->getId());

        if ($path === null) {
            $this->addFlash('error', 'Impossible de sauvegarder l\'image. Formats acceptés : JPEG, PNG, WebP, GIF.');
            return $this->redirectToRoute('app_profile');
        }

        $user->setAvatarUrl($path);
        $em->flush();

        $this->addFlash('success', 'Photo de profil mise à jour !');
        return $this->redirectToRoute('app_profile');
    }

    #[Route('/search', name: 'app_profile_search')]
    public function search(Request $request, UserRepository $userRepo, FriendRepository $friendRepo): Response
    {
        $currentUser = $this->getUser();
        $q = $request->query->get('q', '');
        // Strip @ prefix so users can search by @username
        if (str_starts_with($q, '@')) {
            $q = substr($q, 1);
        }
        $results = $q ? $userRepo->searchByUsername($q, $currentUser) : [];

        // Build a map: userId => Friend|null for each result
        $relationships = [];
        foreach ($results as $user) {
            $relationships[(string) $user->getId()] = $friendRepo->findRelationship($currentUser, $user);
        }

        return $this->render('profile/search.html.twig', [
            'query' => $q,
            'results' => $results,
            'relationships' => $relationships,
        ]);
    }

    #[Route('/friend/add/{id}', name: 'app_friend_add', methods: ['POST'])]
    public function addFriend(User $targetUser, EntityManagerInterface $em, FriendRepository $friendRepo, NotificationService $push): Response
    {
        $user = $this->getUser();

        if ($targetUser === $user) {
            $this->addFlash('error', 'Tu ne peux pas t\'ajouter toi-même.');
            return $this->redirectToRoute('app_profile_search');
        }

        $existing = $friendRepo->findRelationship($user, $targetUser);
        if ($existing) {
            if ($existing->getStatus() === 'refused') {
                $em->remove($existing);
                $em->flush();
            } else {
                $this->addFlash('info', 'Une relation existe déjà avec cet utilisateur.');
                return $this->redirectToRoute('app_profile_search');
            }
        }

        $friend = new Friend();
        $friend->setOwner($user)
            ->setFriendType('inApp')
            ->setFriendUser($targetUser)
            ->setStatus('pending');
        $em->persist($friend);
        $em->flush();

        $notif = new Notification();
        $notif->setRecipient($targetUser)
            ->setType('friend_request')
            ->setTitle('Nouvelle demande d\'ami')
            ->setBody('@' . $user->getUsername() . ' veut vous ajouter en ami.')
            ->setData(['friendId' => (string) $friend->getId()]);
        $em->persist($notif);
        $em->flush();

        $push->sendNotification(
            'Nouvelle demande d\'ami',
            '@' . $user->getUsername() . ' veut t\'ajouter en ami.',
            (string) $targetUser->getId(),
        );

        $this->addFlash('success', 'Demande d\'ami envoyée à @' . $targetUser->getUsername() . ' !');
        return $this->redirectToRoute('app_profile_search');
    }

    #[Route('/friend/remove/{id}', name: 'app_friend_remove', methods: ['POST'])]
    public function removeFriend(Friend $friend, EntityManagerInterface $em): Response
    {
        $user = $this->getUser();
        if ($friend->getOwner() !== $user && $friend->getFriendUser() !== $user) {
            throw $this->createAccessDeniedException();
        }
        $em->remove($friend);
        $em->flush();
        $this->addFlash('success', 'Ami supprimé.');
        return $this->redirectToRoute('app_profile', ['tab' => 'amis']);
    }

    #[Route('/friend/cancel/{id}', name: 'app_friend_cancel', methods: ['POST'])]
    public function cancelFriendRequest(Friend $friend, EntityManagerInterface $em): Response
    {
        $user = $this->getUser();
        if ($friend->getOwner() !== $user) {
            throw $this->createAccessDeniedException();
        }
        $em->remove($friend);
        $em->flush();
        $this->addFlash('info', 'Demande d\'ami annulée.');

        return $this->redirectToRoute('app_profile', ['tab' => 'amis']);
    }

    #[Route('/friend/accept/{id}', name: 'app_friend_accept', methods: ['POST'])]
    public function acceptFriend(Friend $friend, EntityManagerInterface $em, NotificationService $push): Response
    {
        $user = $this->getUser();
        if ($friend->getFriendUser() !== $user) {
            throw $this->createAccessDeniedException();
        }
        $friend->setStatus('confirmed');

        $notif = new Notification();
        $notif->setRecipient($friend->getOwner())
            ->setType('friend_accepted')
            ->setTitle('Demande d\'ami acceptée')
            ->setBody('@' . $user->getUsername() . ' a accepté votre demande d\'ami.');
        $em->persist($notif);
        $em->flush();

        $push->sendNotification(
            'Demande d\'ami acceptée',
            '@' . $user->getUsername() . ' a accepté ta demande d\'ami.',
            (string) $friend->getOwner()->getId(),
        );

        $this->addFlash('success', 'Ami ajouté !');
        return $this->redirectToRoute('app_profile', ['tab' => 'amis']);
    }

    #[Route('/friend/refuse/{id}', name: 'app_friend_refuse', methods: ['POST'])]
    public function refuseFriend(Friend $friend, EntityManagerInterface $em): Response
    {
        $user = $this->getUser();
        if ($friend->getFriendUser() !== $user) {
            throw $this->createAccessDeniedException();
        }
        $em->remove($friend);
        $em->flush();
        $this->addFlash('info', 'Demande refusée.');
        return $this->redirectToRoute('app_profile', ['tab' => 'amis']);
    }

    #[Route('/invite', name: 'app_profile_invite', methods: ['POST'])]
    public function invite(Request $request, MailerInterface $mailer): Response
    {
        $emailAddress = trim((string) $request->request->get('email', ''));

        if (!filter_var($emailAddress, FILTER_VALIDATE_EMAIL)) {
            $this->addFlash('error', 'Adresse email invalide.');
            return $this->redirectToRoute('app_profile');
        }

        $inviter = $this->getUser();
        $registerUrl = $this->generateUrl('app_register', [], UrlGeneratorInterface::ABSOLUTE_URL);

        $html = $this->renderView('emails/friend_invite.html.twig', [
            'inviter'      => $inviter,
            'register_url' => $registerUrl,
        ]);

        $email = (new Email())
            ->from('noreply@iwasthere.app')
            ->to($emailAddress)
            ->subject($inviter->getDisplayName() . ' t\'invite sur IWasThere !')
            ->html($html);

        try {
            $mailer->send($email);
            $this->addFlash('success', 'Invitation envoyée à ' . $emailAddress . ' !');
        } catch (\Throwable) {
            $this->addFlash('error', 'Impossible d\'envoyer l\'email. Réessaie plus tard.');
        }

        return $this->redirectToRoute('app_profile');
    }

    #[Route('/{username}', name: 'app_profile_view')]
    public function view(string $username, UserRepository $userRepo, EventParticipationRepository $partRepo, FriendRepository $friendRepo): Response
    {
        $profileUser = $userRepo->findOneBy(['username' => $username]);
        if (!$profileUser) {
            throw $this->createNotFoundException();
        }

        $currentUser = $this->getUser();
        $isSelf = $profileUser === $currentUser;
        $areFriends = $friendRepo->areFriends($currentUser, $profileUser);
        $relationship = $friendRepo->findRelationship($currentUser, $profileUser);

        $canViewHistory = $isSelf
            || $profileUser->getProfileVisibility() === 'public'
            || ($profileUser->getProfileVisibility() === 'friends' && $areFriends);

        $events = $canViewHistory ? $partRepo->findByUser($profileUser, 10) : [];
        $totalEvents = $canViewHistory ? $partRepo->countByUser($profileUser) : null;

        return $this->render('profile/view.html.twig', [
            'profile_user' => $profileUser,
            'is_self' => $isSelf,
            'are_friends' => $areFriends,
            'relationship' => $relationship,
            'can_view_history' => $canViewHistory,
            'events' => $events,
            'total_events' => $totalEvents,
        ]);
    }
}
