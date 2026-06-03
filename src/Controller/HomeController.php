<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\EventParticipationRepository;
use App\Repository\NotificationRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
class HomeController extends AbstractController
{
    #[Route('/', name: 'app_home')]
    public function index(
        EventParticipationRepository $participationRepo,
        NotificationRepository $notifRepo,
    ): Response {
        $user = $this->getUser();

        $participationRepo->updateStaleUpcoming($user);

        // Next upcoming event
        $nextEvent = $participationRepo->findNextUpcoming($user);

        // Last 3 completed participations
        $recentPast = $participationRepo->findRecentPast($user, 3);

        // Pending reminders (past events with incomplete data - no rating)
        $pendingReminders = $participationRepo->findPendingReminders($user);

        // Unread notifications count (for header)
        $unreadCount = $notifRepo->countUnread($user);

        return $this->render('home/index.html.twig', [
            'next_event' => $nextEvent,
            'recent_past' => $recentPast,
            'pending_reminders' => $pendingReminders,
            'unread_notifications_count' => $unreadCount,
        ]);
    }
}
