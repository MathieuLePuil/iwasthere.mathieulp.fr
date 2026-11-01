<?php

declare(strict_types=1);

namespace App\Ticketmaster;

/**
 * Rapproche les événements nouveaux du flux des artistes que les utilisateurs
 * attendent (wishlist, artistes déjà vus) — logique pure, testée seule.
 *
 * Un artiste est reconnu par son nom normalisé entier dans
 * `artists_normalized` (« | muse | ») : « muse » ne déclenche pas sur « muse
 * by soaked ». Les offres d'un même concert (Ticketmaster en publie une par
 * type de billet) sont réduites à une seule, celle au nom le plus court — la
 * vente standard. Les annulés et les concerts déjà passés ne comptent pas.
 *
 * Le résultat est groupé par utilisateur puis par artiste : une tournée de dix
 * dates fait une seule notification, pas dix.
 */
final class ArtistAnnouncementMatcher
{
    /**
     * @param array<string, array<string, mixed>> $rows      event_id → projection (EventProjector) des événements nouveaux
     * @param array<string, array<string, string>> $interests user_id → artiste normalisé → nom affiché
     *
     * @return array<string, array<string, array{artist: string, concerts: list<array{name: string, venue: ?string, city: ?string, date: ?string, url: ?string, onsale: ?string}>}>>
     *     user_id → artiste normalisé → annonce (concerts par date croissante)
     */
    public static function match(array $rows, array $interests, \DateTimeImmutable $now): array
    {
        if ($rows === [] || $interests === []) {
            return [];
        }

        $wanted = [];
        foreach ($interests as $artists) {
            foreach ($artists as $normalized => $_) {
                $wanted[$normalized] = true;
            }
        }

        $today = ParisTime::toParis($now)->format('Y-m-d');
        // artiste normalisé → clé de concert → concert
        $byArtist = [];
        foreach ($rows as $row) {
            $artists = $row['artists_normalized'] ?? null;
            if (!is_string($artists) || ($row['status'] ?? '') === 'cancelled') {
                continue;
            }
            $date = $row['event_start_local_date'] ?? null;
            if (is_string($date) && $date < $today) {
                continue;
            }
            foreach (array_filter(explode(' | ', trim($artists, '| '))) as $artist) {
                if (!isset($wanted[$artist])) {
                    continue;
                }
                $concert = self::concert($row);
                $key = implode('|', [$concert['venue'] ?? '', $concert['date'] ?? '']);
                $current = $byArtist[$artist][$key] ?? null;
                if ($current === null || mb_strlen($concert['name']) < mb_strlen($current['name'])) {
                    $byArtist[$artist][$key] = $concert;
                }
            }
        }
        if ($byArtist === []) {
            return [];
        }

        $announcements = [];
        foreach ($interests as $userId => $artists) {
            foreach ($artists as $normalized => $label) {
                if (!isset($byArtist[$normalized])) {
                    continue;
                }
                $concerts = array_values($byArtist[$normalized]);
                usort($concerts, static fn (array $a, array $b) => [$a['date'] ?? '9999', $a['venue'] ?? ''] <=> [$b['date'] ?? '9999', $b['venue'] ?? '']);
                $announcements[$userId][$normalized] = ['artist' => $label, 'concerts' => $concerts];
            }
        }

        return $announcements;
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array{name: string, venue: ?string, city: ?string, date: ?string, url: ?string, onsale: ?string}
     */
    private static function concert(array $row): array
    {
        $onsale = null;
        foreach ($row['windows'] ?? [] as $window) {
            if (($window['type'] ?? null) === SaleWindowType::Public->value) {
                $onsale = $window['starts_at_utc'] ?? null;
            }
        }

        return [
            'name' => (string) ($row['name'] ?? ''),
            'venue' => $row['venue_name'] ?? null,
            'city' => $row['venue_city'] ?? null,
            'date' => $row['event_start_local_date'] ?? null,
            'url' => $row['url'] ?? null,
            'onsale' => is_string($onsale) ? $onsale : null,
        ];
    }

    /**
     * La clé « une seule fois » d'une annonce : même artiste et mêmes concerts,
     * même notification — un événement purgé puis revenu ne fait pas re-sonner.
     * Une date de plus dans une tournée est bien une annonce nouvelle.
     *
     * @param list<array{venue: ?string, date: ?string}> $concerts
     */
    public static function dedupeKey(string $artistNormalized, array $concerts): string
    {
        $keys = array_map(static fn (array $c) => ($c['venue'] ?? '') . '|' . ($c['date'] ?? ''), $concerts);
        sort($keys);

        return 'artist:' . md5($artistNormalized . '#' . implode('#', $keys));
    }
}
