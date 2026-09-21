<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\ArtistWatch;
use App\Entity\EventWatch;
use App\Repository\ArtistWatchRepository;
use App\Repository\EventParticipationRepository;
use App\Repository\EventWatchRepository;
use App\Repository\TmEventRepository;
use App\Repository\WatchNotificationRepository;
use App\Ticketmaster\NameNormalizer;
use App\Ticketmaster\SearchCriteria;
use App\Ticketmaster\WatchChannel;
use App\Ticketmaster\WatchService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Alertes d'ouverture de billetterie : recherche du catalogue Ticketmaster
 * (F1), gestion des veilles (F4) et des artistes attendus — wishlist et
 * artistes déjà vus (F5). Les alertes elles-mêmes partent des crons
 * (voir docs/ticketmaster.md).
 */
#[IsGranted('ROLE_USER')]
#[Route('/alerts')]
class AlertsController extends AppController
{
    private const PER_PAGE = 30;

    #[Route('', name: 'app_alerts')]
    public function index(
        EventWatchRepository $watches,
        WatchNotificationRepository $notifications,
        ArtistWatchRepository $artists,
        EventParticipationRepository $participations,
    ): Response {
        $user = $this->user();
        $list = $watches->findForUser($user);
        $seen = $participations->findSeenArtists($user)[$user->getId()->toRfc4122()] ?? [];

        return $this->render('alerts/index.html.twig', [
            'watches' => $list,
            'pending' => $notifications->findPendingForWatches($list),
            'now' => new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
            'channels' => WatchChannel::cases(),
            'wishlist' => $artists->findForUser($user),
            // Comptés sur le nom normalisé : « Muse » et « MUSE » sont le même artiste
            'seen_artists_count' => count(array_unique(array_map(NameNormalizer::normalize(...), $seen))),
        ]);
    }

    /**
     * La recherche. Avec `fragment=1`, ne renvoie que la liste de résultats :
     * c'est ce que le champ appelle au fil de la saisie (live-search).
     */
    #[Route('/search', name: 'app_alerts_search', methods: ['GET'])]
    public function search(Request $request, TmEventRepository $events, EventWatchRepository $watches, ArtistWatchRepository $artists): Response
    {
        $criteria = SearchCriteria::fromQuery($request->query->all());
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $page = max(1, $request->query->getInt('page', 1));

        $result = $events->search($criteria, $now, self::PER_PAGE, ($page - 1) * self::PER_PAGE);
        $totalPages = max(1, (int) ceil($result['total'] / self::PER_PAGE));

        $followed = [];
        foreach ($watches->findForUser($this->user()) as $watch) {
            $followed[$watch->getEvent()->getEventId()] = $watch;
        }

        // En mode artiste, la recherche est aussi la porte d'entrée de la wishlist.
        // On propose les noms exacts des artistes trouvés (« Zaho » tapé → « Zaho de
        // Sagazan » proposé : c'est le nom entier qui est rapproché du flux), ou le
        // terme saisi tel quel quand rien n'est trouvé.
        $wished = null;
        $suggested = [];
        $normalized = $criteria->byArtist() ? NameNormalizer::normalize($criteria->query) : '';
        if ($normalized !== '') {
            $wishlist = [];
            foreach ($artists->findForUser($this->user()) as $a) {
                $wishlist[$a->getArtistNormalized()] = $a;
            }
            $wished = $wishlist[$normalized] ?? null;
            foreach ($result['events'] as $event) {
                $name = $event->getArtistName();
                $key = $name === null ? null : NameNormalizer::normalize($name);
                if ($key === null || $key === '') {
                    continue;
                }
                if (isset($wishlist[$key])) {
                    // « Zaho » tapé, « Zaho de Sagazan » déjà en wishlist : c'est bien lui qu'on regarde
                    $wished ??= $wishlist[$key];
                } elseif (!isset($suggested[$key]) && count($suggested) < 3) {
                    $suggested[$key] = $name;
                }
            }
            if ($suggested !== []) {
                $wished = $wishlist[$normalized] ?? null;
            }
        }

        $vars = [
            'criteria' => $criteria,
            'wished' => $wished,
            'wishable' => $normalized !== '',
            'suggested' => array_values($suggested),
            'events' => $result['events'],
            'total' => $result['total'],
            'offers' => $result['offers'],
            'page' => min($page, $totalPages),
            'total_pages' => $totalPages,
            'followed' => $followed,
            'now' => $now,
        ];

        if ($request->query->getBoolean('fragment')) {
            return $this->render('alerts/_results.html.twig', $vars);
        }

        return $this->render('alerts/search.html.twig', $vars + [
            'segments' => $events->findSegments(),
            'cities' => $events->findCities($criteria, $now),
        ]);
    }

    #[Route('/follow/{eventId}', name: 'app_alerts_follow', methods: ['POST'])]
    public function follow(string $eventId, Request $request, TmEventRepository $events, WatchService $service): Response
    {
        $event = $events->findOneWithWindows($eventId);
        if ($event === null) {
            throw $this->createNotFoundException();
        }

        $watch = $service->follow($this->user(), $event);

        $window = $event->getPublicSaleWindow();
        if ($event->isCancelled()) {
            $this->addFlash('info', 'Événement annulé : veille enregistrée mais inactive.');
        } elseif ($window === null || $window->getStartsAtUtc() <= new \DateTimeImmutable()) {
            $this->addFlash('info', 'Ouverture déjà passée : veille enregistrée, mais aucune alerte J-1 ou H-1 ne partira.');
        } else {
            $this->addFlash('success', sprintf('Tu suis « %s ».', $event->getName()));
        }

        $back = (string) $request->request->get('_back', '');

        return $this->redirect($back !== '' && str_starts_with($back, '/') ? $back : $this->generateUrl('app_alerts', ['_fragment' => 'w-' . $watch->getId()]));
    }

    #[Route('/{id}/settings', name: 'app_alerts_settings', methods: ['POST'])]
    public function settings(EventWatch $watch, Request $request, WatchService $service): Response
    {
        $this->assertOwner($watch);

        $service->update(
            $watch,
            $request->request->getBoolean('notify_j1'),
            $request->request->getBoolean('notify_h1'),
            WatchChannel::tryFrom((string) $request->request->get('channel', '')) ?? WatchChannel::Push,
        );

        return $this->redirectToRoute('app_alerts', ['_fragment' => 'w-' . $watch->getId()]);
    }

    #[Route('/{id}/delete', name: 'app_alerts_delete', methods: ['POST'])]
    public function delete(EventWatch $watch, WatchService $service): Response
    {
        $this->assertOwner($watch);
        $service->remove($watch);
        $this->addFlash('info', 'Veille supprimée.');

        return $this->redirectToRoute('app_alerts');
    }

    /** Ajoute un artiste à la wishlist — depuis la page des veilles ou depuis la recherche (`_back`). */
    #[Route('/artists', name: 'app_alerts_artist_add', methods: ['POST'])]
    public function addArtist(Request $request, ArtistWatchRepository $artists, EntityManagerInterface $em): Response
    {
        $name = trim((string) $request->request->get('artist', ''));
        $normalized = NameNormalizer::normalize($name);
        $back = (string) $request->request->get('_back', '');
        $redirect = $back !== '' && str_starts_with($back, '/') ? $this->redirect($back) : $this->redirectToRoute('app_alerts', ['_fragment' => 'artists']);

        if ($normalized === '' || mb_strlen($name) > 255) {
            $this->addFlash('error', 'Indique un nom d\'artiste.');

            return $redirect;
        }
        if ($artists->findOneForUserAndArtist($this->user(), $normalized) !== null) {
            $this->addFlash('info', sprintf('« %s » est déjà dans ta wishlist.', $name));

            return $redirect;
        }

        $em->persist(new ArtistWatch($this->user(), $name));
        $em->flush();
        $this->addFlash('success', sprintf('« %s » ajouté à ta wishlist : on te prévient à sa prochaine date en France.', $name));

        return $redirect;
    }

    #[Route('/artists/{id}/delete', name: 'app_alerts_artist_delete', methods: ['POST'])]
    public function deleteArtist(ArtistWatch $artist, Request $request, EntityManagerInterface $em): Response
    {
        if ($artist->getUser() !== $this->user()) {
            throw $this->createAccessDeniedException();
        }
        $em->remove($artist);
        $em->flush();

        $back = (string) $request->request->get('_back', '');

        return $back !== '' && str_starts_with($back, '/') ? $this->redirect($back) : $this->redirectToRoute('app_alerts', ['_fragment' => 'artists']);
    }

    /** Bascule « me prévenir quand un artiste que j'ai déjà vu annonce une date en France ». */
    #[Route('/artists/seen', name: 'app_alerts_seen_toggle', methods: ['POST'])]
    public function toggleSeenArtists(Request $request, EntityManagerInterface $em): Response
    {
        $this->user()->setAlertSeenArtists($request->request->getBoolean('alert_seen_artists'));
        $em->flush();

        return $this->redirectToRoute('app_alerts', ['_fragment' => 'artists']);
    }

    private function assertOwner(EventWatch $watch): void
    {
        if ($watch->getUser() !== $this->user()) {
            throw $this->createAccessDeniedException();
        }
    }
}
