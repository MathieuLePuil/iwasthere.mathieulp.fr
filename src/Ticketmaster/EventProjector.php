<?php

declare(strict_types=1);

namespace App\Ticketmaster;

/**
 * Projette un événement brut du Discovery Feed sur les champs qu'on garde —
 * et rien d'autre. Pur : pas de base, pas d'horloge.
 *
 * Champs délibérément ignorés (voir docs/ticketmaster.md) :
 *  - `apiOnsaleStartDateTime` : décalé de +1 h sur 100 % du catalogue France,
 *    bug de conversion de fuseau côté Ticketmaster. On lit `onsaleStartDateTime`.
 *  - `presales[]` est lu mais vide sur toute la France ; s'il se remplit un
 *    jour, les préventes deviennent des fenêtres `presale` sans autre travail.
 *  - `resaleEventUrl`, `transactable`, `hotEvent` : constants.
 *
 * `attractions[].attraction.attractionName` est en revanche exploitable : sur le
 * flux du 19/09/2026, 68 % des événements Music portent le nom de l'artiste
 * (« Muse » là où `eventName` dit « MUSE », « PRESTATION HOSPITALITE MUSE »,
 * « MUSE - VIP »). C'est ce qui permet une recherche par artiste qui ne
 * remonte pas les musées.
 *
 * Le résultat est un tableau prêt pour l'écriture SQL par lots, avec un hash
 * qui résume tout ce qui compte : si le hash n'a pas bougé, la ligne non plus.
 */
final class EventProjector
{
    /**
     * @param array<string, mixed> $raw     un élément de `events[]`
     * @param list<string>|null    $segments segments à garder (null = tous)
     *
     * @return array<string, mixed>|null null si l'événement est hors segments ou inexploitable
     */
    public static function project(array $raw, ?array $segments): ?array
    {
        $eventId = self::string($raw['eventId'] ?? null);
        $name = self::string($raw['eventName'] ?? null);
        $segment = self::string($raw['classificationSegment'] ?? null);
        if ($eventId === null || $name === null) {
            return null;
        }
        if ($segments !== null && !in_array($segment, $segments, true)) {
            return null;
        }

        // Sans date d'ouverture il n'y a rien à planifier : l'événement n'entre pas
        $onsaleStart = self::utc($raw['onsaleStartDateTime'] ?? null);
        if ($onsaleStart === null) {
            return null;
        }

        $windows = [[
            'type' => SaleWindowType::Public->value,
            'label' => '',
            'starts_at_utc' => $onsaleStart,
            'ends_at_utc' => self::utc($raw['onsaleEndDateTime'] ?? null),
            'url' => self::string($raw['primaryEventUrl'] ?? null, 500),
        ]];
        foreach (self::presales($raw) as $presale) {
            $windows[] = $presale;
        }

        $venue = is_array($raw['venue'] ?? null) ? $raw['venue'] : [];
        $artists = self::artists($raw);

        $row = [
            'event_id' => mb_substr($eventId, 0, 64),
            'name' => mb_substr($name, 0, 255),
            'name_normalized' => mb_substr(NameNormalizer::normalize($name), 0, 255),
            'status' => mb_substr(strtolower(self::string($raw['eventStatus'] ?? null) ?? 'onsale'), 0, 20),
            'event_start_utc' => self::utc($raw['eventStartDateTime'] ?? null),
            'event_start_local_date' => self::localDate($raw['eventStartLocalDate'] ?? null),
            'event_start_local_time' => self::localTime($raw['eventStartLocalTime'] ?? null),
            'venue_name' => self::string($venue['venueName'] ?? null, 255),
            'venue_city' => self::string($venue['venueCity'] ?? null, 120),
            'venue_timezone' => self::timezone($venue['venueTimezone'] ?? null),
            'segment' => $segment === null ? null : mb_substr($segment, 0, 60),
            'genre' => self::string($raw['classificationGenre'] ?? null, 60),
            'url' => self::string($raw['primaryEventUrl'] ?? null, 500),
            'source' => self::string($raw['source'] ?? null, 40),
            'artist_name' => $artists[0] ?? null,
            'artists_normalized' => $artists === [] ? null : mb_substr(' | ' . implode(' | ', array_map(NameNormalizer::normalize(...), $artists)) . ' | ', 0, 1000),
            'windows' => $windows,
        ];
        $row['payload_hash'] = md5((string) json_encode($row));

        return $row;
    }

    /**
     * Les préventes, si Ticketmaster en expose un jour. Le format n'est pas
     * documenté sur le feed ; on lit les noms de champs de l'API Discovery et
     * leurs variantes probables, et on ignore ce qu'on ne comprend pas.
     *
     * @param array<string, mixed> $raw
     *
     * @return list<array<string, mixed>>
     */
    private static function presales(array $raw): array
    {
        $presales = $raw['presales'] ?? null;
        if (!is_array($presales)) {
            return [];
        }

        $windows = [];
        foreach ($presales as $i => $presale) {
            if (!is_array($presale)) {
                continue;
            }
            $start = self::utc($presale['startDateTime'] ?? $presale['presaleStartDateTime'] ?? null);
            if ($start === null) {
                continue;
            }
            $label = self::string($presale['name'] ?? $presale['presaleName'] ?? null, 120) ?? 'Prévente ' . ($i + 1);
            $windows[] = [
                'type' => SaleWindowType::Presale->value,
                'label' => $label,
                'starts_at_utc' => $start,
                'ends_at_utc' => self::utc($presale['endDateTime'] ?? $presale['presaleEndDateTime'] ?? null),
                'url' => self::string($presale['url'] ?? null, 500),
            ];
        }

        return $windows;
    }

    /**
     * Les noms d'artistes (« attractions »), dans l'ordre du flux.
     *
     * @param array<string, mixed> $raw
     *
     * @return list<string>
     */
    private static function artists(array $raw): array
    {
        $attractions = $raw['attractions'] ?? null;
        if (!is_array($attractions)) {
            return [];
        }

        $names = [];
        foreach ($attractions as $item) {
            $attraction = is_array($item['attraction'] ?? null) ? $item['attraction'] : $item;
            $name = is_array($attraction) ? self::string($attraction['attractionName'] ?? $attraction['name'] ?? null, 255) : null;
            if ($name !== null && !in_array($name, $names, true)) {
                $names[] = $name;
            }
        }

        return $names;
    }

    private static function string(mixed $value, ?int $max = null): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        return $max === null ? $value : mb_substr($value, 0, $max);
    }

    /** Un instant ISO 8601 du flux → « Y-m-d H:i:s » en UTC, tel que la base le stocke */
    private static function utc(mixed $value): ?string
    {
        if (!is_string($value) || $value === '') {
            return null;
        }
        try {
            return (new \DateTimeImmutable($value))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        } catch (\Exception) {
            return null;
        }
    }

    private static function localDate(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : null;
    }

    private static function localTime(mixed $value): ?string
    {
        if (!is_string($value) || !preg_match('/^(\d{2}):(\d{2})(?::(\d{2}))?$/', $value, $m)) {
            return null;
        }

        return sprintf('%s:%s:%s', $m[1], $m[2], $m[3] ?? '00');
    }

    private static function timezone(mixed $value): ?string
    {
        if (!is_string($value) || !in_array($value, \DateTimeZone::listIdentifiers(), true)) {
            return null;
        }

        return $value;
    }
}
