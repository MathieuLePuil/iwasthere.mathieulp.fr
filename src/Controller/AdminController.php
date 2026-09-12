<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\AuditLog;
use App\Entity\Event;
use App\Entity\EventParticipation;
use App\Entity\User;
use App\Entity\Venue;
use App\Event\EventType;
use App\Http\Input;
use App\Repository\AuditLogRepository;
use App\Repository\EventParticipationRepository;
use App\Repository\EventRepository;
use App\Repository\UserRepository;
use App\Repository\VenueRepository;
use App\Participation\ParticipationService;
use App\Service\AccountDeletionService;
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
    public function editUser(User $user, Request $request, UserRepository $userRepo): Response
    {
        if ($request->isMethod('POST')) {
            $old = ['email' => $user->getEmail(), 'role' => $user->getRole(), 'displayName' => $user->getDisplayName()];

            $email = mb_strtolower(Input::text($request->request->get('email'), 180) ?? '');
            $role = (string) $request->request->get('role', $user->getRole());
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $this->addFlash('error', 'Adresse email invalide.');

                return $this->redirectToRoute('app_admin_user_edit', ['id' => $user->getId()]);
            }
            $taken = $userRepo->findOneByEmail($email);
            if ($taken !== null && $taken !== $user) {
                $this->addFlash('error', 'Cette adresse est déjà utilisée par un autre compte.');

                return $this->redirectToRoute('app_admin_user_edit', ['id' => $user->getId()]);
            }
            if (!in_array($role, User::ROLES, true)) {
                $this->addFlash('error', 'Rôle inconnu.');

                return $this->redirectToRoute('app_admin_user_edit', ['id' => $user->getId()]);
            }

            $user->setDisplayName(Input::text($request->request->get('display_name'), 100) ?? $user->getDisplayName());
            $user->setEmail($email);
            $user->setBio(Input::text($request->request->get('bio'), 1000));
            $user->setRole($role);

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
    public function deleteUser(User $user, AccountDeletionService $accountDeletion): Response
    {
        if ($user === $this->getUser()) {
            $this->addFlash('error', 'Tu ne peux pas supprimer ton propre compte depuis l\'admin.');
            return $this->redirectToRoute('app_admin_users');
        }

        // Journaliser avant : l'entité n'a plus d'id lisible une fois supprimée.
        $this->logAction('delete', 'User', (string) $user->getId(), null, $user->getEmail());
        $accountDeletion->delete($user);
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

            if ($date = Input::date($request->request->get('date'))) {
                $event->setDate($date);
            }
            $event->setArtistName(Input::text($request->request->get('artist_name'), 255));
            $event->setTournamentName(Input::text($request->request->get('tournament_name'), 255));
            $event->setTeams(Input::text($request->request->get('teams'), 255));
            $type = EventType::tryFrom((string) $request->request->get('type', ''));
            if ($type !== null) {
                $event->setType($type->value)->setCategory($type->category()->value);
            }

            $venueId = Input::uuid($request->request->get('venue_id'));
            $event->setVenue($venueId ? $venueRepo->find($venueId) : null);

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
    public function deleteParticipation(Event $event, string $pid, EventParticipationRepository $partRepo, ParticipationService $participations): Response
    {
        $participation = Uuid::isValid($pid) ? $partRepo->find($pid) : null;
        if ($participation && $participation->getEvent()->getId()->equals($event->getId())) {
            $this->logAction('delete', 'EventParticipation', $pid);
            $participations->remove($participation);
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
    public function deleteEvent(Event $event, EventParticipationRepository $participationRepo, ParticipationService $participations): Response
    {
        foreach ($participationRepo->findByEvent($event) as $participation) {
            $participations->remove($participation);
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

            $venue->setName(Input::text($request->request->get('name'), 255) ?? $venue->getName());
            $venue->setAddress(Input::text($request->request->get('address'), 255) ?? '');
            $venue->setLatitude(max(-90.0, min(90.0, (float) $request->request->get('latitude', $venue->getLatitude()))));
            $venue->setLongitude(max(-180.0, min(180.0, (float) $request->request->get('longitude', $venue->getLongitude()))));
            $venue->setCapacity(Input::int($request->request->get('capacity'), 1, 1000000));
            $venue->setVenueType(Input::text($request->request->get('venue_type'), 20));
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
