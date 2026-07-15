<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\EventParticipationRepository;
use App\Repository\NotificationRepository;
use App\Service\FeedService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
class HomeController extends AbstractController
{
    /** Événements d'amis affichés en aperçu sur l'accueil */
    private const FEED_PREVIEW_MAX = 3;

    #[Route('/', name: 'app_home')]
    public function index(
        EventParticipationRepository $participationRepo,
        NotificationRepository $notifRepo,
        FeedService $feedService,
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

        // Micro-stat sous le prénom : événements déjà vécus dans l'année en cours
        $currentYear = (int) (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Paris')))->format('Y');
        $yearCount = $participationRepo->countHistory($user, 'past', '', (string) $currentYear);

        // Friends activity preview (full feed lives at /feed)
        $feed = $feedService->buildFeed($user);

        // Le feed regroupe par jour puis par amis/type/lieu ; ici on veut les
        // 3 derniers événements à plat, chacun avec le libellé de date de son jour
        $feedPreview = [];
        foreach ($feed['days'] as $day) {
            foreach ($day['groups'] as $group) {
                foreach ($group['events'] as $event) {
                    $event['date_label'] = $day['date_label'];
                    $feedPreview[] = $event;
                    if (count($feedPreview) === self::FEED_PREVIEW_MAX) {
                        break 3;
                    }
                }
            }
        }

        return $this->render('home/index.html.twig', [
            'next_event' => $nextEvent,
            'recent_past' => $recentPast,
            'pending_reminders' => $pendingReminders,
            'unread_notifications_count' => $unreadCount,
            'feed_preview' => $feedPreview,
            'feed_friend_count' => $feed['friend_count'],
            'year_count' => $yearCount,
            'current_year' => $currentYear,
        ]);
    }
}
