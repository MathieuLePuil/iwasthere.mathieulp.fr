<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Friend;
use App\Entity\Notification;
use App\Repository\NotificationRepository;
use App\Service\PushService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
#[Route('/notifications')]
class NotificationsController extends AbstractController
{
    #[Route('', name: 'app_notifications')]
    public function index(NotificationRepository $notifRepo, EntityManagerInterface $em): Response
    {
        $user = $this->getUser();
        $notifications = $notifRepo->findForUser($user, 30);

        foreach ($notifications as $notif) {
            if (!$notif->isRead()) {
                $notif->setIsRead(true);
            }
        }
        $em->flush();

        return $this->render('notifications/index.html.twig', [
            'notifications' => $notifications,
        ]);
    }

    #[Route('/count', name: 'app_notifications_count', methods: ['GET'])]
    public function count(NotificationRepository $notifRepo): JsonResponse
    {
        return $this->json(['count' => $notifRepo->countUnread($this->getUser())]);
    }

    #[Route('/{id}/delete', name: 'app_notification_delete', methods: ['POST'])]
    public function delete(Notification $notification, EntityManagerInterface $em): Response
    {
        if ($notification->getRecipient() !== $this->getUser()) {
            throw $this->createAccessDeniedException();
        }
        $em->remove($notification);
        $em->flush();

        return $this->redirectToRoute('app_notifications');
    }

    #[Route('/delete-all', name: 'app_notification_delete_all', methods: ['POST'])]
    public function deleteAll(NotificationRepository $notifRepo, EntityManagerInterface $em): Response
    {
        $user = $this->getUser();
        $notifications = $notifRepo->findForUser($user, 200);
        foreach ($notifications as $notif) {
            $em->remove($notif);
        }
        $em->flush();

        return $this->redirectToRoute('app_notifications');
    }

    #[Route('/friend-request/{id}/accept', name: 'app_friend_request_accept', methods: ['POST'])]
    public function acceptFriendRequest(
        Friend $friend,
        EntityManagerInterface $em,
        NotificationRepository $notifRepo,
        PushService $push,
        Request $request,
    ): Response {
        $user = $this->getUser();
        if ($friend->getFriendUser() !== $user) {
            throw $this->createAccessDeniedException();
        }
        $friend->setStatus('confirmed');

        // Remove the friend_request notification
        $requestNotif = $notifRepo->findFriendRequestNotification($user, (string) $friend->getId());
        if ($requestNotif) {
            $em->remove($requestNotif);
        }

        // Notify the sender that their request was accepted
        $notif = new Notification();
        $notif->setRecipient($friend->getOwner())
            ->setType('friend_accepted')
            ->setTitle('Demande d\'ami acceptée')
            ->setBody('@' . $user->getUsername() . ' a accepté votre demande d\'ami.');
        $em->persist($notif);

        $em->flush();

        $push->sendToUser(
            $friend->getOwner(),
            'Demande d\'ami acceptée',
            '@' . $user->getUsername() . ' a accepté ta demande d\'ami.',
            '/profile/' . $user->getUsername(),
        );

        $this->addFlash('success', 'Demande d\'ami acceptée !');

        $redirect = $request->request->get('_redirect', '');
        return $this->redirectToRoute($redirect === 'profile' ? 'app_profile' : 'app_notifications', $redirect === 'profile' ? ['tab' => 'amis'] : []);
    }

    #[Route('/friend-request/{id}/refuse', name: 'app_friend_request_refuse', methods: ['POST'])]
    public function refuseFriendRequest(
        Friend $friend,
        EntityManagerInterface $em,
        NotificationRepository $notifRepo,
        Request $request,
    ): Response {
        $user = $this->getUser();
        if ($friend->getFriendUser() !== $user) {
            throw $this->createAccessDeniedException();
        }

        // Remove the friend_request notification
        $requestNotif = $notifRepo->findFriendRequestNotification($user, (string) $friend->getId());
        if ($requestNotif) {
            $em->remove($requestNotif);
        }

        $em->remove($friend);
        $em->flush();
        $this->addFlash('info', 'Demande d\'ami refusée.');

        $redirect = $request->request->get('_redirect', '');
        return $this->redirectToRoute($redirect === 'profile' ? 'app_profile' : 'app_notifications', $redirect === 'profile' ? ['tab' => 'amis'] : []);
    }
}
