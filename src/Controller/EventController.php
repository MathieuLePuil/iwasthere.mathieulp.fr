<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Event;
use App\Entity\EventParticipation;
use App\Entity\Notification;
use App\Entity\User;
use App\Entity\Venue;
use App\Notification\ActivityNotifier;
use App\Notification\NotificationDispatcher;
use App\Notification\NotificationType;
use App\Repository\EventParticipationRepository;
use App\Repository\EventRepository;
use App\Repository\FriendRepository;
use App\Repository\NotificationRepository;
use App\Repository\UserRepository;
use App\Repository\VenueRepository;
use App\Service\DeezerArtistService;
use App\Service\EventImageService;
use App\Service\IcsExporter;
use App\Service\SetlistFmService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
#[Route('/event')]
class EventController extends AbstractController
{
    private const TENNIS_WINNER_REQUIRED = 'Indique le vainqueur du match : un score de tennis ne permet pas de le déduire.';

    /**
     * A tennis score is written from the winner's side ("6/1 7/6" reads the same whether
     * the winner is named first or second), so unlike a "2 - 0" it cannot say who won —
     * the Vainqueur checkbox is the only source (see Event::getScoreline()). A score
     * without it would be stored as a result nobody can read, so it is refused.
     */
    private function tennisWinnerMissing(array $data): bool
    {
        return trim($data['final_score'] ?? '') !== ''
            && !in_array($data['winner'] ?? '', ['1', '2'], true);
    }

    #[Route('/new', name: 'app_event_new')]
    public function new(
        Request $request,
        EntityManagerInterface $em,
        VenueRepository $venueRepo,
        EventRepository $eventRepo,
        FriendRepository $friendRepo,
        UserRepository $userRepo,
        SetlistFmService $setlistFm,
        NotificationDispatcher $notifier,
        ActivityNotifier $activity,
        DeezerArtistService $deezer,
    ): Response {
        if ($request->isMethod('POST')) {
            $data = $request->request->all();

            // Refused before anything is created, so a rejected form writes nothing.
            // Only past events carry a score (the souvenir block is hidden otherwise),
            // same convention as Event::isPast().
            $date = !empty($data['date']) ? new \DateTimeImmutable($data['date']) : null;
            if (($data['type'] ?? '') === 'tennis'
                && $date && $date < new \DateTimeImmutable('today')
                && $this->tennisWinnerMissing($data)
            ) {
                $this->addFlash('error', self::TENNIS_WINNER_REQUIRED);

                // Renvoyé sur le formulaire tennis, pas sur le formulaire musique par
                // défaut : sans ces paramètres la page revient sur la mauvaise catégorie.
                return $this->redirectToRoute('app_event_new', [
                    'category' => 'sport',
                    'type'     => 'tennis',
                ]);
            }

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

                // Le formulaire ne renvoie `existing_event_id` que si l'utilisateur a
                // cliqué une suggestion ; en saisissant le nom à la main il créerait un
                // jumeau. On rattache donc à l'existant, sinon deux amis au même concert
                // se retrouvent sur deux lignes et ne se voient jamais.
                if ($twin = $eventRepo->findDuplicate($event)) {
                    $event = $twin;
                } else {
                    $em->persist($event);
                }
            }

            // Artist picture via Deezer (new event, or joined one still missing it)
            if ($event && !$event->getArtistImageUrl()) {
                $deezer->applyToEvent($event);
            }

            // Souvenir data (score, note, durée…) only exists once the event is past
            if (!$event->isPast()) {
                unset(
                    $data['final_score'], $data['score_team1'], $data['score_team2'],
                    $data['winner'], $data['duration'], $data['rating'], $data['comment'],
                );
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
            // Winner checkbox exists only for tennis; dual-score sports derive it from the score
            if (($data['type'] ?? '') === 'tennis' && in_array($data['winner'] ?? '', ['1', '2'], true)) {
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
                    ->setStatus($event->getDate() >= new \DateTimeImmutable('today') ? 'upcoming' : 'past');

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
                    $notifier->dispatch(
                        $taggedUser,
                        NotificationType::FriendTaggedInEvent,
                        $me->getDisplayName() . ' t\'a ajouté à un événement',
                        $event->getArtistName() ?? $event->getTournamentName() ?? 'Événement',
                        $this->generateUrl('app_notifications'),
                        [
                            'eventId'         => (string) $event->getId(),
                            'participationId' => (string) $participation->getId(),
                            'eventName'       => $event->getArtistName() ?? $event->getTournamentName() ?? 'Événement',
                        ],
                    );
                }
                if (!empty($friendsData)) {
                    $em->flush();
                }

                // Annonce aux autres amis : « X ira à », ou « X y sera aussi »
                // pour ceux qui ont déjà cet événement
                $activity->announceParticipation($participation);

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
            'address' => $v->getAddress(),
            'lat' => $v->getLatitude(),
            'lng' => $v->getLongitude(),
        ], $venues));
    }

    /**
     * Le fichier .ics du bouton « Ajouter au calendrier ».
     *
     * Déclarée avant app_event_show : cette route est plus spécifique, et l'ordre de
     * déclaration départage chez Symfony.
     */
    #[Route('/{id}/calendar.ics', name: 'app_event_calendar')]
    public function calendar(Event $event, IcsExporter $ics): Response
    {
        $url = $this->generateUrl('app_event_show', ['id' => $event->getId()], UrlGeneratorInterface::ABSOLUTE_URL);

        return new Response($ics->export($event, $url), Response::HTTP_OK, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            // Sans disposition explicite, le navigateur affiche le .ics en texte brut
            // au lieu de le passer à l'agenda.
            'Content-Disposition' => HeaderUtils::makeDisposition(
                HeaderUtils::DISPOSITION_ATTACHMENT,
                $ics->filename($event),
            ),
        ]);
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
        NotificationDispatcher $notifier,
        ActivityNotifier $activity,
        DeezerArtistService $deezer,
    ): Response {
        $user = $this->getUser();
        $participation = $participationRepo->findByUserAndEvent($user, $event);

        if (!$participation) {
            // Auto-create participation
            $participation = new EventParticipation();
            $participation->setEvent($event)->setUser($user)
                ->setStatus($event->getDate() < new \DateTimeImmutable('today') ? 'past' : 'upcoming');
            $em->persist($participation);
            $event->setParticipantCount($event->getParticipantCount() + 1);
        }

        if ($request->isMethod('POST')) {
            $data = $request->request->all();

            // Checked before the type below is applied, so getType() still holds what the
            // form was rendered with: the Vainqueur checkbox only exists when the event was
            // already tennis. Demanding it from someone who just switched football → tennis
            // would reject a form they have no control to fix.
            if ($event->getType() === 'tennis' && $event->isPast()
                && ($data['type'] ?? 'tennis') === 'tennis'
                && $this->tennisWinnerMissing($data)
            ) {
                $this->addFlash('error', self::TENNIS_WINNER_REQUIRED);

                return $this->redirectToRoute('app_event_edit', ['id' => $event->getId()]);
            }

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
                $previousArtist = $event->getArtistName();
                $event->setArtistName($data['artist_name']);
                if ($event->getArtistName() !== $previousArtist) {
                    $event->setArtistImageUrl(null);
                }
            }
            if ($event->getCategory() === 'music' && !$event->getArtistImageUrl()) {
                $deezer->applyToEvent($event);
            }
            if (!empty($data['teams'])) {
                $event->setTeams($data['teams']);
            }
            if (!empty($data['tournament_name'])) {
                $event->setTournamentName($data['tournament_name']);
            }

            // Souvenir data (score, setlist, note, durée…) only once the event is past
            if (!$event->isPast()) {
                unset(
                    $data['final_score'], $data['winner'], $data['setlist'],
                    $data['setlist_encores'], $data['rating'], $data['comment'], $data['duration'],
                );
            }

            // Sport specific — score and winner are shared event data
            if (isset($data['final_score'])) {
                $event->setFinalScore($data['final_score'] !== '' ? $data['final_score'] : null);
            }
            if ($event->getCategory() === 'sport' && $event->isPast()) {
                // Winner checkbox exists only for tennis ('1'/'2' when checked, absent
                // when cleared); the other sports derive the winner from the score.
                $event->setWinner(
                    $event->getType() === 'tennis' && in_array($data['winner'] ?? '', ['1', '2'], true)
                        ? $data['winner'] : null
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
            // Le statut se déduit de la date de l'événement
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
                $notifier->dispatch(
                    $taggedUser,
                    NotificationType::FriendTaggedInEvent,
                    $me->getDisplayName() . ' t\'a ajouté à un événement',
                    $event->getArtistName() ?? $event->getTournamentName() ?? 'Événement',
                    $this->generateUrl('app_notifications'),
                    [
                        'eventId'         => (string) $event->getId(),
                        'participationId' => (string) $participation->getId(),
                        'eventName'       => $event->getArtistName() ?? $event->getTournamentName() ?? 'Événement',
                    ],
                );
            }
            $em->flush();

            // Le souvenir n'est annoncé qu'une fois : `announceMemory` se
            // dédoublonne sur la participation, les retouches ne repoussent pas
            if ($participation->getRating() || $participation->getComment() || $participation->getImageUrl()) {
                $activity->announceMemory($participation);
            }

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

        if (!$event->isPast()) {
            $this->addFlash('error', 'Tu pourras ajouter une photo une fois l\'événement passé.');
            return $this->redirectToRoute('app_event_show', ['id' => $event->getId()]);
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
                    ->setStatus($event->getDate() >= new \DateTimeImmutable('today') ? 'upcoming' : 'past');
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

    /**
     * « Oui, on y va ensemble. » L'accord doit être mutuel : tant que l'autre n'a
     * pas répondu oui de son côté, on ne fait qu'enregistrer la réponse et la
     * question reste en attente. C'est le second oui qui crée la relation.
     */
    #[Route('/together/{id}/yes', name: 'app_event_together_yes', methods: ['POST'])]
    public function togetherYes(
        Notification $notification,
        EntityManagerInterface $em,
        UserRepository $userRepo,
        EventRepository $eventRepo,
        EventParticipationRepository $participationRepo,
        NotificationRepository $notifRepo,
        NotificationDispatcher $notifier,
    ): Response {
        $user = $this->getUser();
        [$event, $other] = $this->resolveTogether($notification, $user, $eventRepo, $userRepo);

        $mine = $participationRepo->findByUserAndEvent($user, $event);
        $theirs = $participationRepo->findByUserAndEvent($other, $event);
        if (!$mine || !$theirs) {
            // L'un des deux s'est retiré entre-temps : la question n'a plus d'objet
            $em->remove($notification);
            $em->flush();
            $this->addFlash('info', 'Cet événement n\'est plus partagé avec ' . $other->getDisplayName() . '.');

            return $this->redirectToRoute('app_notifications');
        }

        $theirQuestion = $notifRepo->findTogetherQuestion($other, (string) $event->getId(), (string) $user->getId());
        $theySaidYes = ($theirQuestion?->getData()['answer'] ?? null) === 'yes';

        if (!$theySaidYes) {
            $data = $notification->getData() ?? [];
            $data['answer'] = 'yes';
            $notification->setData($data);
            $em->flush();

            $this->addFlash('success', 'C\'est noté — en attente de la réponse de ' . $other->getDisplayName() . '.');

            return $this->redirectToRoute('app_notifications');
        }

        $this->linkCompanions($mine, $theirs);
        $em->remove($notification);
        $em->remove($theirQuestion);
        $em->flush();

        // L'autre a dit oui en premier : sans ça, il n'apprendrait jamais l'issue
        $notifier->dispatch(
            $other,
            NotificationType::FriendSameEvent,
            'Vous y allez ensemble',
            $user->getDisplayName() . ' a confirmé — vous êtes notés ensemble sur '
                . ($event->getArtistName() ?? $event->getTournamentName() ?? $event->getTeams() ?? 'cet événement') . '.',
            $this->generateUrl('app_event_show', ['id' => (string) $event->getId()]),
            ['eventId' => (string) $event->getId()],
        );

        $this->addFlash('success', 'Vous y allez ensemble avec ' . $other->getDisplayName() . ' !');

        return $this->redirectToRoute('app_event_show', ['id' => (string) $event->getId()]);
    }

    /**
     * « Non. » Un seul refus tranche pour la paire : les deux questions tombent,
     * et chacun garde l'événement de son côté.
     */
    #[Route('/together/{id}/no', name: 'app_event_together_no', methods: ['POST'])]
    public function togetherNo(
        Notification $notification,
        EntityManagerInterface $em,
        UserRepository $userRepo,
        EventRepository $eventRepo,
        NotificationRepository $notifRepo,
    ): Response {
        $user = $this->getUser();
        [$event, $other] = $this->resolveTogether($notification, $user, $eventRepo, $userRepo);

        $theirQuestion = $notifRepo->findTogetherQuestion($other, (string) $event->getId(), (string) $user->getId());
        if ($theirQuestion) {
            $em->remove($theirQuestion);
        }
        $em->remove($notification);
        $em->flush();

        $this->addFlash('info', 'C\'est noté — vous y allez chacun de votre côté.');

        return $this->redirectToRoute('app_notifications');
    }

    /**
     * Valide que la notification est bien une question « ensemble ? » adressée à
     * cet utilisateur, et en extrait l'événement et l'autre participant.
     *
     * @return array{0: Event, 1: User}
     */
    private function resolveTogether(
        Notification $notification,
        User $user,
        EventRepository $eventRepo,
        UserRepository $userRepo,
    ): array {
        if ($notification->getRecipient() !== $user
            || $notification->getType() !== NotificationType::FriendSameEvent->value) {
            throw $this->createAccessDeniedException();
        }

        $data = $notification->getData() ?? [];
        $event = isset($data['eventId']) ? $eventRepo->find($data['eventId']) : null;
        $other = isset($data['otherUserId']) ? $userRepo->find($data['otherUserId']) : null;

        if (!$event || !$other) {
            throw $this->createNotFoundException();
        }

        return [$event, $other];
    }

    /** Inscrit chacun comme accompagnant de l'autre — la relation est symétrique. */
    private function linkCompanions(EventParticipation $a, EventParticipation $b): void
    {
        foreach ([[$a, $b], [$b, $a]] as [$participation, $companion]) {
            $user = $companion->getUser();
            $friends = $participation->getFriends();

            foreach ($friends as $f) {
                if (($f['type'] ?? '') === 'app' && ($f['userId'] ?? '') === (string) $user->getId()) {
                    continue 2;
                }
            }

            $friends[] = [
                'type' => 'app',
                'userId' => (string) $user->getId(),
                'username' => $user->getUsername(),
                'displayName' => $user->getDisplayName(),
            ];
            $participation->setFriends($friends);
        }
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
