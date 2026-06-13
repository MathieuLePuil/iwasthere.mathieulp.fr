<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\AuditLog;
use App\Entity\Event;
use App\Entity\EventParticipation;
use App\Entity\User;
use App\Entity\Venue;
use App\Repository\AuditLogRepository;
use App\Repository\EventParticipationRepository;
use App\Repository\EventRepository;
use App\Repository\UserRepository;
use App\Repository\VenueRepository;
use App\Service\SetlistFmService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

#[IsGranted('ROLE_SUPER_ADMIN')]
#[Route('/admin')]
class AdminController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {}

    private function logAction(
        string $action,
        string $entityType,
        string $entityId,
        ?string $field = null,
        ?string $oldValue = null,
        ?string $newValue = null,
    ): void {
        $log = new AuditLog();
        $log->setSuperAdminUserId($this->getUser()->getId())
            ->setAction($action)
            ->setEntityType($entityType)
            ->setEntityId($entityId)
            ->setFieldChanged($field)
            ->setOldValue($oldValue)
            ->setNewValue($newValue);
        $this->em->persist($log);
    }

    #[Route('', name: 'app_admin_dashboard')]
    public function dashboard(
        UserRepository $userRepo,
        EventRepository $eventRepo,
        VenueRepository $venueRepo,
    ): Response {
        return $this->render('admin/dashboard.html.twig', [
            'total_users' => count($userRepo->findAll()),
            'total_events' => count($eventRepo->findAll()),
            'total_venues' => count($venueRepo->findAll()),
        ]);
    }

    // ===== USERS =====

    #[Route('/users', name: 'app_admin_users')]
    public function users(Request $request, UserRepository $userRepo): Response
    {
        $q = $request->query->get('q', '');
        $users = $q
            ? $userRepo->adminSearch($q)
            : $userRepo->findBy([], ['createdAt' => 'DESC'], 50);

        return $this->render('admin/users.html.twig', ['users' => $users, 'q' => $q]);
    }

    #[Route('/users/{id}', name: 'app_admin_user_show')]
    public function userShow(User $user, EventParticipationRepository $partRepo): Response
    {
        $participations = $partRepo->findByUser($user, 20);

        return $this->render('admin/user_show.html.twig', [
            'profile_user' => $user,
            'participations' => $participations,
        ]);
    }

    #[Route('/users/{id}/edit', name: 'app_admin_user_edit', methods: ['GET', 'POST'])]
    public function editUser(User $user, Request $request): Response
    {
        if ($request->isMethod('POST')) {
            $old = ['email' => $user->getEmail(), 'role' => $user->getRole(), 'displayName' => $user->getDisplayName()];

            $user->setDisplayName((string) $request->request->get('display_name', $user->getDisplayName()));
            $user->setEmail((string) $request->request->get('email', $user->getEmail()));
            $user->setBio($request->request->get('bio') ?: null);
            $user->setRole((string) $request->request->get('role', $user->getRole()));

            if ($old['email'] !== $user->getEmail()) {
                $this->logAction('update', 'User', (string) $user->getId(), 'email', $old['email'], $user->getEmail());
            }
            if ($old['role'] !== $user->getRole()) {
                $this->logAction('update', 'User', (string) $user->getId(), 'role', $old['role'], $user->getRole());
            }
            if ($old['displayName'] !== $user->getDisplayName()) {
                $this->logAction('update', 'User', (string) $user->getId(), 'displayName', $old['displayName'], $user->getDisplayName());
            }

            $this->em->flush();
            $this->addFlash('success', 'Utilisateur mis à jour.');

            return $this->redirectToRoute('app_admin_user_show', ['id' => $user->getId()]);
        }

        return $this->render('admin/user_edit.html.twig', ['profile_user' => $user]);
    }

    #[Route('/users/{id}/delete', name: 'app_admin_user_delete', methods: ['POST'])]
    public function deleteUser(User $user): Response
    {
        if ($user === $this->getUser()) {
            $this->addFlash('error', 'Tu ne peux pas supprimer ton propre compte depuis l\'admin.');
            return $this->redirectToRoute('app_admin_users');
        }

        $this->logAction('delete', 'User', (string) $user->getId(), null, $user->getEmail());
        $this->em->remove($user);
        $this->em->flush();
        $this->addFlash('success', 'Utilisateur supprimé.');

        return $this->redirectToRoute('app_admin_users');
    }

    // ===== EVENTS =====

    #[Route('/events', name: 'app_admin_events')]
    public function events(Request $request, EventRepository $eventRepo): Response
    {
        $q = $request->query->get('q', '');
        $events = $q
            ? $eventRepo->search($q)
            : $eventRepo->findBy([], ['createdAt' => 'DESC']);

        return $this->render('admin/events.html.twig', ['events' => $events, 'q' => $q]);
    }

    #[Route('/events/{id}', name: 'app_admin_event_show')]
    public function eventShow(Event $event, EventParticipationRepository $partRepo): Response
    {
        return $this->render('admin/event_show.html.twig', [
            'event' => $event,
            'participations' => $partRepo->findByEvent($event),
        ]);
    }

    #[Route('/events/{id}/edit', name: 'app_admin_event_edit', methods: ['GET', 'POST'])]
    public function editEvent(Event $event, Request $request, VenueRepository $venueRepo): Response
    {
        if ($request->isMethod('POST')) {
            $old = $event->getArtistName() ?? $event->getTournamentName();

            $dateStr = $request->request->get('date');
            if ($dateStr) {
                $event->setDate(new \DateTimeImmutable($dateStr));
            }
            $event->setArtistName($request->request->get('artist_name') ?: null);
            $event->setTournamentName($request->request->get('tournament_name') ?: null);
            $event->setTeams($request->request->get('teams') ?: null);
            $event->setType((string) $request->request->get('type', $event->getType()));

            $venueId = $request->request->get('venue_id');
            if ($venueId) {
                $venue = $venueRepo->find($venueId);
                $event->setVenue($venue);
            } else {
                $event->setVenue(null);
            }

            $event->setUpdatedAt(new \DateTime());
            $this->logAction('update', 'Event', (string) $event->getId(), null, $old, $event->getArtistName() ?? $event->getTournamentName());
            $this->em->flush();
            $this->addFlash('success', 'Événement mis à jour.');

            return $this->redirectToRoute('app_admin_event_show', ['id' => $event->getId()]);
        }

        return $this->render('admin/event_edit.html.twig', [
            'event' => $event,
            'venues' => $venueRepo->findBy([], ['name' => 'ASC']),
        ]);
    }

    #[Route('/events/{id}/participations/{pid}/delete', name: 'app_admin_participation_delete', methods: ['POST'])]
    public function deleteParticipation(Event $event, string $pid, EventParticipationRepository $partRepo): Response
    {
        $participation = $partRepo->find($pid);
        if ($participation && $participation->getEvent()->getId()->equals($event->getId())) {
            $this->logAction('delete', 'EventParticipation', $pid);
            $this->em->remove($participation);
            $this->em->flush();
            $this->addFlash('success', 'Participation supprimée.');
        }

        return $this->redirectToRoute('app_admin_event_show', ['id' => $event->getId()]);
    }

    #[Route('/events/{id}/setlist/force-import', name: 'app_admin_event_setlist_import', methods: ['POST'])]
    public function forceSetlistImport(Event $event, SetlistFmService $setlistService): Response
    {
        $event->setSetlistRetryCount(0);
        $success = $setlistService->tryImportSetlist($event);
        $this->logAction('update', 'Event', (string) $event->getId(), 'setlist', null, 'force_imported');

        $this->addFlash(
            $success ? 'success' : 'error',
            $success ? 'Setlist importée depuis Setlist.fm !' : 'Setlist non trouvée sur Setlist.fm.'
        );

        return $this->redirectToRoute('app_admin_events');
    }

    #[Route('/events/{id}/delete', name: 'app_admin_event_delete', methods: ['POST'])]
    public function deleteEvent(Event $event, EventParticipationRepository $participationRepo): Response
    {
        foreach ($participationRepo->findByEvent($event) as $participation) {
            $this->em->remove($participation);
        }
        $this->logAction('delete', 'Event', (string) $event->getId(), null, $event->getArtistName() ?? $event->getTournamentName());
        $this->em->remove($event);
        $this->em->flush();
        $this->addFlash('success', 'Événement supprimé.');

        return $this->redirectToRoute('app_admin_events');
    }

    // ===== VENUES =====

    #[Route('/venues', name: 'app_admin_venues')]
    public function venues(Request $request, VenueRepository $venueRepo): Response
    {
        $q = $request->query->get('q', '');
        $venues = $q
            ? $venueRepo->search($q)
            : $venueRepo->findBy([], ['name' => 'ASC']);

        return $this->render('admin/venues.html.twig', ['venues' => $venues, 'q' => $q]);
    }

    #[Route('/venues/{id}/edit', name: 'app_admin_venue_edit', methods: ['GET', 'POST'])]
    public function editVenue(Venue $venue, Request $request): Response
    {
        if ($request->isMethod('POST')) {
            $old = $venue->getName();

            $venue->setName((string) $request->request->get('name', $venue->getName()));
            $venue->setAddress((string) $request->request->get('address', $venue->getAddress()));
            $venue->setCity((string) $request->request->get('city', $venue->getCity()));
            $venue->setCountry((string) $request->request->get('country', $venue->getCountry()));
            $venue->setLatitude((float) $request->request->get('latitude', $venue->getLatitude()));
            $venue->setLongitude((float) $request->request->get('longitude', $venue->getLongitude()));
            $venue->setCapacity($request->request->get('capacity') !== '' ? (int) $request->request->get('capacity') : null);
            $venue->setVenueType($request->request->get('venue_type') ?: null);
            $venue->setUpdatedAt(new \DateTime());

            $this->logAction('update', 'Venue', (string) $venue->getId(), 'name', $old, $venue->getName());
            $this->em->flush();
            $this->addFlash('success', 'Lieu mis à jour.');

            return $this->redirectToRoute('app_admin_venues');
        }

        return $this->render('admin/venue_edit.html.twig', ['venue' => $venue]);
    }

    #[Route('/venues/{id}/delete', name: 'app_admin_venue_delete', methods: ['POST'])]
    public function deleteVenue(Venue $venue): Response
    {
        $this->logAction('delete', 'Venue', (string) $venue->getId(), null, $venue->getName());
        $this->em->remove($venue);
        $this->em->flush();
        $this->addFlash('success', 'Lieu supprimé.');

        return $this->redirectToRoute('app_admin_venues');
    }

    // ===== AUDIT LOG =====

    #[Route('/audit', name: 'app_admin_audit')]
    public function audit(AuditLogRepository $auditRepo): Response
    {
        $logs = $auditRepo->findBy([], ['performedAt' => 'DESC'], 100);

        return $this->render('admin/audit.html.twig', ['logs' => $logs]);
    }
}
