<?php

declare(strict_types=1);

namespace App\Ticketmaster;

use App\Notification\NotificationDispatcher;
use App\Notification\NotificationType;
use App\Repository\ArtistWatchRepository;
use App\Repository\EventParticipationRepository;
use App\Repository\UserRepository;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * « Nouvelle date d'un artiste » : après chaque synchronisation, les événements
 * qui viennent d'entrer dans le catalogue sont rapprochés de deux sources —
 * la wishlist (ArtistWatch) et, pour qui l'a activé, les artistes déjà vus du
 * journal. Une notification par utilisateur et par artiste, qui mène à la
 * recherche : de là, « Suivre » pose la veille J-1 / H-1.
 *
 * Le rapprochement lui-même est dans ArtistAnnouncementMatcher (pur). Ici :
 * charger les intérêts, rédiger, dispatcher. Le push suit la préférence
 * `artist_announced` de l'utilisateur, comme tout le reste.
 */
final class ArtistAnnouncer
{
    private const MAX_LISTED = 3;

    public function __construct(
        private readonly ArtistWatchRepository $artistWatches,
        private readonly EventParticipationRepository $participations,
        private readonly UserRepository $users,
        private readonly NotificationDispatcher $dispatcher,
        private readonly UrlGeneratorInterface $urls,
    ) {}

    /**
     * @param array<string, array<string, mixed>> $newRows event_id → projection des événements nouveaux dans le catalogue
     *
     * @return int notifications créées
     */
    public function announce(array $newRows, \DateTimeImmutable $now): int
    {
        $artists = self::artistsOf($newRows);
        if ($artists === []) {
            return 0;
        }

        $announcements = ArtistAnnouncementMatcher::match($newRows, $this->interests($artists), $now);
        if ($announcements === []) {
            return 0;
        }

        $created = 0;
        foreach ($this->users->findByIds(array_keys($announcements)) as $user) {
            foreach ($announcements[$user->getId()->toRfc4122()] ?? [] as $normalized => $announcement) {
                $text = self::compose($announcement, $now);
                $onsaleToCome = array_filter($announcement['concerts'], static fn (array $c) => $c['onsale'] !== null && $c['onsale'] > $now->format('Y-m-d H:i:s'));
                $url = $this->urls->generate('app_alerts_search', [
                    'q' => $announcement['artist'],
                    'onsale' => $onsaleToCome === [] ? 'onsale' : null,
                ]);

                if ($this->dispatcher->dispatch(
                    $user,
                    NotificationType::ArtistAnnounced,
                    $text['title'],
                    $text['body'],
                    $url,
                    ['artist' => $announcement['artist']],
                    null,
                    ArtistAnnouncementMatcher::dedupeKey($normalized, $announcement['concerts']),
                )) {
                    $created++;
                }
            }
        }

        return $created;
    }

    /**
     * Titre et corps d'une annonce — « Muse annonce 3 dates en France » puis
     * les premières, salle et jour, et « … et 2 autres ».
     *
     * @param array{artist: string, concerts: list<array{venue: ?string, city: ?string, date: ?string}>} $announcement
     *
     * @return array{title: string, body: string}
     */
    public static function compose(array $announcement, \DateTimeImmutable $now): array
    {
        $concerts = $announcement['concerts'];
        $count = count($concerts);
        $title = sprintf('%s annonce %s en France', $announcement['artist'], $count === 1 ? 'une date' : $count . ' dates');

        $lines = [];
        foreach (array_slice($concerts, 0, self::MAX_LISTED) as $concert) {
            $place = $concert['city'] ?? $concert['venue'] ?? 'Lieu à préciser';
            if ($concert['date'] !== null) {
                $place .= ' · ' . ParisTime::dayLocal(new \DateTimeImmutable($concert['date'], ParisTime::zone()), $now);
            }
            $lines[] = $place;
        }
        $body = implode(', ', $lines);
        if ($count > self::MAX_LISTED) {
            $body .= sprintf(' et %d autre%s', $count - self::MAX_LISTED, $count - self::MAX_LISTED > 1 ? 's' : '');
        }

        // Tiret plutôt que point : les mois abrégés finissent déjà par un point (« nov. »)
        return ['title' => $title, 'body' => $body . ' — pose une veille pour être prévenu de l\'ouverture.'];
    }

    /**
     * Les artistes cités par ces événements, normalisés.
     *
     * @param array<string, array<string, mixed>> $rows
     *
     * @return list<string>
     */
    private static function artistsOf(array $rows): array
    {
        $artists = [];
        foreach ($rows as $row) {
            $list = $row['artists_normalized'] ?? null;
            if (is_string($list)) {
                foreach (array_filter(explode(' | ', trim($list, '| '))) as $artist) {
                    $artists[$artist] = true;
                }
            }
        }

        return array_keys($artists);
    }

    /**
     * Wishlist, puis artistes déjà vus des utilisateurs qui l'ont demandé,
     * restreints aux artistes présents dans les nouveautés. Un artiste à la
     * fois en wishlist et dans le journal ne compte qu'une fois — la wishlist
     * l'emporte pour la graphie.
     *
     * @param list<string> $artists
     *
     * @return array<string, array<string, string>> user_id → artiste normalisé → nom affiché
     */
    private function interests(array $artists): array
    {
        $interests = $this->artistWatches->findInterests($artists);
        $wanted = array_fill_keys($artists, true);

        foreach ($this->participations->findSeenArtists() as $userId => $names) {
            foreach ($names as $name) {
                $normalized = NameNormalizer::normalize($name);
                if (isset($wanted[$normalized]) && !isset($interests[$userId][$normalized])) {
                    $interests[$userId][$normalized] = $name;
                }
            }
        }

        return $interests;
    }
}
