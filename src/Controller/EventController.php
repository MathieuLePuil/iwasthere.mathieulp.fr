<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Event;
use App\Entity\EventParticipation;
use App\Entity\Notification;
use App\Entity\Venue;
use App\Repository\EventParticipationRepository;
use App\Repository\EventRepository;
use App\Repository\FriendRepository;
use App\Repository\NotificationRepository;
use App\Repository\UserRepository;
use App\Repository\VenueRepository;
use App\Service\EventImageService;
use App\Service\NotificationService;
use App\Service\SetlistFmService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
#[Route('/event')]
class EventController extends AbstractController
{
    #[Route('/new', name: 'app_event_new')]
    public function new(
        Request $request,
        EntityManagerInterface $em,
        VenueRepository $venueRepo,
        EventRepository $eventRepo,
        FriendRepository $friendRepo,
        UserRepository $userRepo,
        SetlistFmService $setlistFm,
        NotificationService $push,
    ): Response {
        if ($request->isMethod('POST')) {
            $data = $request->request->all();

            // Find or create venue
            $venue = null;
            if (!empty($data['venue_id'])) {
                $venue = $venueRepo->find($data['venue_id']);
            } elseif (!empty(trim($data['venue_name'] ?? ''))) {
                $venueName = trim($data['venue_name']);
                // Reuse an existing venue with the same name (case/whitespace-insensitive)
                // instead of creating an identical duplicate.
                $venue = $venueRepo->findOneByName($venueName);
                if (!$venue) {
                    $venue = new Venue();
                    $venue->setName($venueName)
                        ->setAddress('')
                        ->setCity('')
                        ->setCountry('France')
                        ->setLatitude(0.0)
                        ->setLongitude(0.0)
                        ->setCreatedByUserId($this->getUser()->getId());
                    $em->persist($venue);
                }
            }

            // Check if joining existing event
            if (!empty($data['existing_event_id'])) {
                $event = $eventRepo->find($data['existing_event_id']);
            } else {
                $event = new Event();
                $event->setCategory($data['category'])
                    ->setType($data['type'])
                    ->setDate(new \DateTimeImmutable($data['date']))
                    ->setCreatedByUserId($this->getUser()->getId());

                if (!empty($data['start_time'])) {
                    $event->setStartTime(new \DateTimeImmutable($data['start_time']));
                }

                if ($venue) {
                    $event->setVenue($venue);
                }
                if (!empty($data['artist_name'])) {
                    $event->setArtistName($data['artist_name']);
                }
                if (!empty($data['tournament_name'])) {
                    $event->setTournamentName($data['tournament_name']);
                }
                // Combine team1 + team2 into teams field
                $team1 = trim($data['team1'] ?? $data['teams'] ?? '');
                $team2 = trim($data['team2'] ?? '');
                if ($team1 || $team2) {
                    $teams = $team2 ? "$team1 vs $team2" : $team1;
                    $event->setTeams($teams);
                }
                $em->persist($event);
            }

            // Match result is shared event data — set it whether the event is new
            // or joined, so every participant sees the same score/winner.
            $finalScore = $data['final_score'] ?? '';
            if (empty($finalScore) && isset($data['score_team1'], $data['score_team2'])) {
                $s1 = trim($data['score_team1']);
                $s2 = trim($data['score_team2']);
                if ($s1 !== '' || $s2 !== '') {
                    $finalScore = ($s1 !== '' ? $s1 : '0') . ' - ' . ($s2 !== '' ? $s2 : '0');
                }
            }
            if (!empty($finalScore)) {
                $event->setFinalScore($finalScore);
            }
            if (in_array($data['winner'] ?? '', ['1', '2'], true)) {
                $event->setWinner($data['winner']);
            }

            // Create participation
            $existing = $em->getRepository(EventParticipation::class)->findOneBy([
                'event' => $event,
                'user' => $this->getUser(),
            ]);

            if (!$existing) {
                $participation = new EventParticipation();
                $participation->setEvent($event)
                    ->setUser($this->getUser())
                    ->setStatus($event->getDate() >= new \DateTimeImmutable('today') ? 'upcoming' : 'past')
                    ->setVisibility($this->getUser()->getDefaultEventVisibility());

if (!empty($data['duration'])) {
                    $participation->setDuration((int) $data['duration']);
                }
                if (!empty($data['rating'])) {
                    $participation->setRating((int) $data['rating']);
                }
                if (!empty($data['comment'])) {
                    $participation->setComment($data['comment']);
                }

                // Build friends list
                $friendsData = [];
                foreach ($data['friends_app'] ?? [] as $uid) {
                    $friendUser = $userRepo->find($uid);
                    if ($friendUser) {
                        $friendsData[] = [
                            'type'        => 'app',
                            'userId'      => (string) $friendUser->getId(),
                            'username'    => $friendUser->getUsername(),
                            'displayName' => $friendUser->getDisplayName(),
                        ];
                    }
                }
                foreach ($data['friends_external'] ?? [] as $name) {
                    $name = trim((string) $name);
                    if ($name !== '') {
                        $friendsData[] = ['type' => 'external', 'name' => $name];
                    }
                }
                if ($friendsData) {
                    $participation->setFriends($friendsData);
                }

                $em->persist($participation);
                $event->setParticipantCount($event->getParticipantCount() + 1);

                $em->flush();

                // Notify tagged app friends
                $me = $this->getUser();
                foreach ($friendsData as $friend) {
                    if (($friend['type'] ?? '') !== 'app') {
                        continue;
                    }
                    $taggedUser = $userRepo->find($friend['userId']);
                    if (!$taggedUser) {
                        continue;
                    }
                    $notif = new Notification();
                    $notif->setRecipient($taggedUser)
                        ->setType('friend_tagged_in_event')
                        ->setTitle($me->getDisplayName() . ' t\'a ajouté à un événement')
                        ->setBody($event->getArtistName() ?? $event->getTournamentName() ?? 'Événement')
                        ->setData([
                            'eventId'         => (string) $event->getId(),
                            'participationId' => (string) $participation->getId(),
                            'eventName'       => $event->getArtistName() ?? $event->getTournamentName() ?? 'Événement',
                        ]);
                    $em->persist($notif);
                    $push->sendNotification(
                        $me->getDisplayName() . ' t\'a ajouté à un événement',
                        $event->getArtistName() ?? $event->getTournamentName() ?? 'Événement',
                        (string) $taggedUser->getId(),
                    );
                }
                if (!empty($friendsData)) {
                    $em->flush();
                }

                // Auto-import setlist for past music events
                if ($event->getCategory() === 'music' && $event->getDate() < new \DateTimeImmutable('today')) {
                    $setlistFm->tryImportSetlist($event);
                }

                $this->addFlash('success', 'Événement ajouté à ton journal !');

                return $this->redirectToRoute('app_event_show', ['id' => $event->getId()]);
            }

            $em->flush();
            $this->addFlash('info', 'Tu participes déjà à cet événement.');

            return $this->redirectToRoute('app_event_show', ['id' => $event->getId()]);
        }

        $locked = $request->query->has('category');
        $category = $request->query->get('category', 'music');
        $type = $request->query->get('type', $category === 'sport' ? 'football' : 'concert');

        $confirmedFriends = $friendRepo->findConfirmedFriends($this->getUser());

        return $this->render('event/new.html.twig', [
            'category'          => $category,
            'type'              => $type,
            'locked'            => $locked,
            'confirmed_friends' => $confirmedFriends,
        ]);
    }

    #[Route('/search', name: 'app_event_search')]
    public function search(Request $request, EventRepository $eventRepo): JsonResponse
    {
        $q = $request->query->get('q', '');
        if (strlen($q) < 2) {
            return $this->json([]);
        }

        $events = $eventRepo->search($q);

        return $this->json(array_map(fn ($e) => [
            'id' => (string) $e->getId(),
            'type' => $e->getType(),
            'name' => $e->getArtistName() ?? $e->getTournamentName(),
            'date' => $e->getDate()->format('d/m/Y'),
            'venue' => $e->getVenue()?->getName(),
            'city' => $e->getVenue()?->getCity(),
            'participants' => $e->getParticipantCount(),
        ], $events));
    }

    #[Route('/teams/search', name: 'app_teams_search')]
    public function teamsSearch(Request $request, EventRepository $eventRepo): JsonResponse
    {
        $q = $request->query->get('q', '');
        if (strlen($q) < 2) {
            return $this->json([]);
        }

        return $this->json($eventRepo->searchTeams($q));
    }

    #[Route('/artists/search', name: 'app_artists_search')]
    public function artistsSearch(Request $request, EventRepository $eventRepo): JsonResponse
    {
        $q = $request->query->get('q', '');
        if (strlen($q) < 2) {
            return $this->json([]);
        }

        return $this->json($eventRepo->searchArtists($q));
    }

    #[Route('/tournaments/search', name: 'app_tournaments_search')]
    public function tournamentsSearch(Request $request, EventRepository $eventRepo): JsonResponse
    {
        $q = $request->query->get('q', '');
        if (strlen($q) < 2) {
            return $this->json([]);
        }

        return $this->json($eventRepo->searchTournaments($q));
    }

    #[Route('/venues/search', name: 'app_venue_search')]
    public function venueSearch(Request $request, VenueRepository $venueRepo): JsonResponse
    {
        $q = $request->query->get('q', '');
        if (strlen($q) < 2) {
            return $this->json([]);
        }

        $venues = $venueRepo->search($q);

        return $this->json(array_map(fn ($v) => [
            'id' => (string) $v->getId(),
            'name' => $v->getName(),
            'city' => $v->getCity(),
            'country' => $v->getCountry(),
            'address' => $v->getAddress(),
            'lat' => $v->getLatitude(),
            'lng' => $v->getLongitude(),
        ], $venues));
    }

    #[Route('/{id}', name: 'app_event_show')]
    public function show(Event $event, EventParticipationRepository $participationRepo): Response
    {
        $user = $this->getUser();
        $participation = $participationRepo->findByUserAndEvent($user, $event);

        // Get all participants' public/visible data
        $allParticipations = $participationRepo->findVisibleForEvent($event, $user);

        // Detect if the current user is tagged in someone else's participation
        $taggedIn = null;
        if (!$participation) {
            $userId = (string) $user->getId();
            foreach ($allParticipations as $p) {
                foreach ($p->getFriends() ?? [] as $friend) {
                    if (($friend['type'] ?? '') === 'app' && ($friend['userId'] ?? '') === $userId) {
                        $taggedIn = $p;
                        break 2;
                    }
                }
            }
        }

        return $this->render('event/show.html.twig', [
            'event'              => $event,
            'participation'      => $participation,
            'all_participations' => $allParticipations,
            'tagged_in'          => $taggedIn,
        ]);
    }

    #[Route('/{id}/edit', name: 'app_event_edit')]
    public function edit(
        Event $event,
        Request $request,
        EntityManagerInterface $em,
        EventParticipationRepository $participationRepo,
        FriendRepository $friendRepo,
        UserRepository $userRepo,
        NotificationService $push,
    ): Response {
        $user = $this->getUser();
        $participation = $participationRepo->findByUserAndEvent($user, $event);

        if (!$participation) {
            // Auto-create participation
            $participation = new EventParticipation();
            $participation->setEvent($event)->setUser($user)
                ->setStatus($event->getDate() < new \DateTimeImmutable('today') ? 'past' : 'upcoming')
                ->setVisibility($user->getDefaultEventVisibility());
            $em->persist($participation);
            $event->setParticipantCount($event->getParticipantCount() + 1);
        }

        if ($request->isMethod('POST')) {
            $data = $request->request->all();

            // Update event factual data (any participant can edit)
            if (!empty($data['date'])) {
                $event->setDate(new \DateTimeImmutable($data['date']));
            }
            if (array_key_exists('start_time', $data)) {
                $event->setStartTime(
                    $data['start_time'] !== '' ? new \DateTimeImmutable($data['start_time']) : null
                );
            }
            if (!empty($data['type'])) {
                $event->setType($data['type']);
            }
            if (!empty($data['artist_name'])) {
                $event->setArtistName($data['artist_name']);
            }
            if (!empty($data['teams'])) {
                $event->setTeams($data['teams']);
            }
            if (!empty($data['tournament_name'])) {
                $event->setTournamentName($data['tournament_name']);
            }

            // Sport specific — score and winner are shared event data
            if (isset($data['final_score'])) {
                $event->setFinalScore($data['final_score'] !== '' ? $data['final_score'] : null);
            }
            if ($event->getCategory() === 'sport') {
                // Checkbox: '1'/'2' when checked, absent when cleared (draw/unknown)
                $event->setWinner(
                    in_array($data['winner'] ?? '', ['1', '2'], true) ? $data['winner'] : null
                );
            }

            // Update setlist if manually entered (allow editing even setlist_fm sources)
            if (isset($data['setlist']) && is_array($data['setlist'])) {
                $setlistLines = array_values(array_filter(array_map('trim', $data['setlist'])));
                if (!empty($setlistLines)) {
                    $event->setSetlist($setlistLines)->setSetlistSource('manual');
                }
            }
            if (isset($data['setlist_encores']) && is_array($data['setlist_encores'])) {
                $encoreLines = array_values(array_filter(array_map('trim', $data['setlist_encores'])));
                $event->setSetlistEncores($encoreLines ?: null);
            }

            // Update personal data
            if (isset($data['rating']) && $data['rating'] !== '') {
                $participation->setRating((int) $data['rating']);
            }
            if (isset($data['comment'])) {
                $participation->setComment($data['comment']);
            }
            if (isset($data['duration']) && $data['duration'] !== '') {
                $participation->setDuration((int) $data['duration']);
            }
            // Status derived from event date; visibility from user default
            $participation->setStatus(
                $event->getDate() >= new \DateTimeImmutable('today') ? 'upcoming' : 'past'
            );

            // Friends
            $oldAppFriendIds = array_column(
                array_filter($participation->getFriends() ?? [], fn($f) => ($f['type'] ?? '') === 'app'),
                'userId'
            );
            $friendsData = [];
            foreach ($data['friends_app'] ?? [] as $uid) {
                $friendUser = $userRepo->find($uid);
                if ($friendUser) {
                    $friendsData[] = [
                        'type'        => 'app',
                        'userId'      => (string) $friendUser->getId(),
                        'username'    => $friendUser->getUsername(),
                        'displayName' => $friendUser->getDisplayName(),
                    ];
                }
            }
            foreach ($data['friends_external'] ?? [] as $name) {
                $name = trim((string) $name);
                if ($name !== '') {
                    $friendsData[] = ['type' => 'external', 'name' => $name];
                }
            }
            $participation->setFriends($friendsData);

            $em->flush();

            // Notify newly added app friends
            $me = $this->getUser();
            foreach ($friendsData as $friend) {
                if (($friend['type'] ?? '') !== 'app') {
                    continue;
                }
                if (in_array($friend['userId'], $oldAppFriendIds, true)) {
                    continue;
                }
                $taggedUser = $userRepo->find($friend['userId']);
                if (!$taggedUser) {
                    continue;
                }
                $notif = new Notification();
                $notif->setRecipient($taggedUser)
                    ->setType('friend_tagged_in_event')
                    ->setTitle($me->getDisplayName() . ' t\'a ajouté à un événement')
                    ->setBody($event->getArtistName() ?? $event->getTournamentName() ?? 'Événement')
                    ->setData([
                        'eventId'         => (string) $event->getId(),
                        'participationId' => (string) $participation->getId(),
                        'eventName'       => $event->getArtistName() ?? $event->getTournamentName() ?? 'Événement',
                    ]);
                $em->persist($notif);
                $push->sendNotification(
                    $me->getDisplayName() . ' t\'a ajouté à un événement',
                    $event->getArtistName() ?? $event->getTournamentName() ?? 'Événement',
                    (string) $taggedUser->getId(),
                );
            }
            $em->flush();

            $this->addFlash('success', 'Fiche mise à jour !');

            return $this->redirectToRoute('app_event_show', ['id' => $event->getId()]);
        }

        $confirmedFriends = $friendRepo->findConfirmedFriends($user);

        return $this->render('event/edit.html.twig', [
            'event'             => $event,
            'participation'     => $participation,
            'confirmed_friends' => $confirmedFriends,
        ]);
    }

    #[Route('/{id}/image', name: 'app_event_image', methods: ['POST'])]
    public function uploadImage(
        Event $event,
        Request $request,
        EventImageService $images,
        EventParticipationRepository $participationRepo,
        EntityManagerInterface $em,
    ): Response {
        $user = $this->getUser();
        $participation = $participationRepo->findByUserAndEvent($user, $event);
        if (!$participation) {
            throw $this->createAccessDeniedException();
        }

        $file = $request->files->get('image');
        if (!$file) {
            $this->addFlash('error', 'Aucun fichier reçu.');
            return $this->redirectToRoute('app_event_show', ['id' => $event->getId()]);
        }

        $path = $images->saveUploadedFile($file, (string) $event->getId(), (string) $user->getId());
        if ($path === null) {
            $this->addFlash('error', 'Impossible de sauvegarder l\'image. Formats acceptés : JPEG, PNG, WebP, GIF.');
            return $this->redirectToRoute('app_event_show', ['id' => $event->getId()]);
        }

        $participation->setImageUrl($path);
        $em->flush();

        $this->addFlash('success', 'Ta photo de l\'événement a été ajoutée !');

        return $this->redirectToRoute('app_event_show', ['id' => $event->getId()]);
    }

    #[Route('/{id}/image/delete', name: 'app_event_image_delete', methods: ['POST'])]
    public function deleteImage(
        Event $event,
        EventImageService $images,
        EventParticipationRepository $participationRepo,
        EntityManagerInterface $em,
    ): Response {
        $user = $this->getUser();
        $participation = $participationRepo->findByUserAndEvent($user, $event);
        if (!$participation) {
            throw $this->createAccessDeniedException();
        }

        $images->delete((string) $event->getId(), (string) $user->getId());
        $participation->setImageUrl(null);
        $em->flush();

        $this->addFlash('info', 'Ta photo de l\'événement a été supprimée.');

        return $this->redirectToRoute('app_event_show', ['id' => $event->getId()]);
    }

    #[Route('/tag/{id}/accept', name: 'app_event_tag_accept', methods: ['POST'])]
    public function tagAccept(
        Notification $notification,
        EntityManagerInterface $em,
        EventRepository $eventRepo,
        EventParticipationRepository $participationRepo,
    ): Response {
        $user = $this->getUser();
        if ($notification->getRecipient() !== $user || $notification->getType() !== 'friend_tagged_in_event') {
            throw $this->createAccessDeniedException();
        }

        $data = $notification->getData() ?? [];
        $eventId = $data['eventId'] ?? null;

        if ($eventId) {
            $event = $eventRepo->find($eventId);
            if ($event && !$participationRepo->findByUserAndEvent($user, $event)) {
                $newParticipation = new EventParticipation();
                $newParticipation->setEvent($event)
                    ->setUser($user)
                    ->setStatus($event->getDate() >= new \DateTimeImmutable('today') ? 'upcoming' : 'past')
                    ->setVisibility($user->getDefaultEventVisibility());
                $em->persist($newParticipation);
                $event->setParticipantCount($event->getParticipantCount() + 1);
            }
        }

        $em->remove($notification);
        $em->flush();

        $this->addFlash('success', 'Événement ajouté à ton journal !');

        return $this->redirectToRoute('app_event_show', ['id' => $eventId]);
    }

    #[Route('/tag/{id}/decline', name: 'app_event_tag_decline', methods: ['POST'])]
    public function tagDecline(
        Notification $notification,
        EntityManagerInterface $em,
    ): Response {
        $user = $this->getUser();
        if ($notification->getRecipient() !== $user || $notification->getType() !== 'friend_tagged_in_event') {
            throw $this->createAccessDeniedException();
        }

        $em->remove($notification);
        $em->flush();

        return $this->redirectToRoute('app_notifications');
    }

    #[Route('/participation/{id}/remove-me', name: 'app_event_participation_remove_me', methods: ['POST'])]
    public function removeMeFromParticipation(
        EventParticipation $participation,
        EntityManagerInterface $em,
        NotificationRepository $notifRepo,
    ): Response {
        $user = $this->getUser();
        $userId = (string) $user->getId();

        $updatedFriends = array_values(array_filter(
            $participation->getFriends() ?? [],
            fn($f) => !(($f['type'] ?? '') === 'app' && ($f['userId'] ?? '') === $userId)
        ));
        $participation->setFriends($updatedFriends);

        $notif = $notifRepo->findEventTagNotification($user, (string) $participation->getId());
        if ($notif) {
            $em->remove($notif);
        }

        $em->flush();

        $this->addFlash('info', 'Tu as été retiré de cet événement.');

        return $this->redirectToRoute('app_event_show', ['id' => $participation->getEvent()->getId()]);
    }

    #[Route('/{id}/delete', name: 'app_event_delete', methods: ['POST'])]
    public function delete(
        Event $event,
        EntityManagerInterface $em,
        EventParticipationRepository $participationRepo,
    ): Response {
        $user = $this->getUser();
        $participation = $participationRepo->findByUserAndEvent($user, $event);

        if ($participation) {
            $em->remove($participation);
            $event->setParticipantCount(max(0, $event->getParticipantCount() - 1));
            $em->flush();
            $this->addFlash('success', 'Événement supprimé de ton journal.');
        }

        return $this->redirectToRoute('app_home');
    }
}
