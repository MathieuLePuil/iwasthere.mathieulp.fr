<?php

declare(strict_types=1);

namespace App\Ticketmaster;

/**
 * Les filtres de la recherche d'événements (F1), tels que lus dans la query
 * string. Tout est validé ici : le contrôleur et le repository n'ont plus qu'à
 * s'y fier.
 */
final readonly class SearchCriteria
{
    public const DEFAULT_SEGMENT = 'Music';
    public const ALL_SEGMENTS = 'all';

    public const ONSALE_UPCOMING = 'upcoming';
    public const ONSALE_OPEN = 'onsale';

    /** Le terme est un nom d'artiste (défaut) — ou n'importe quoi : nom d'événement, salle */
    public const MODE_ARTIST = 'artist';
    public const MODE_ALL = 'all';

    public function __construct(
        public string $query = '',
        /** Un segment du catalogue, ou self::ALL_SEGMENTS */
        public string $segment = self::DEFAULT_SEGMENT,
        public string $city = '',
        public ?\DateTimeImmutable $from = null,
        public ?\DateTimeImmutable $to = null,
        /** self::ONSALE_UPCOMING (ouverture à venir) ou self::ONSALE_OPEN (déjà en vente) */
        public string $onsale = self::ONSALE_UPCOMING,
        public string $mode = self::MODE_ARTIST,
    ) {}

    /** @param array<string, mixed> $query la query string brute */
    public static function fromQuery(array $query): self
    {
        $str = static fn (string $key, int $max): string => mb_substr(trim((string) ($query[$key] ?? '')), 0, $max);

        $segment = $str('segment', 60);
        $onsale = $str('onsale', 10);

        return new self(
            query: $str('q', 120),
            segment: $segment === '' ? self::DEFAULT_SEGMENT : $segment,
            city: $str('city', 120),
            from: self::date($str('from', 10)),
            to: self::date($str('to', 10)),
            onsale: $onsale === self::ONSALE_OPEN ? self::ONSALE_OPEN : self::ONSALE_UPCOMING,
            mode: $str('mode', 10) === self::MODE_ALL ? self::MODE_ALL : self::MODE_ARTIST,
        );
    }

    private static function date(string $value): ?\DateTimeImmutable
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, new \DateTimeZone('UTC'));

        return $date === false ? null : $date;
    }

    public function allSegments(): bool
    {
        return $this->segment === self::ALL_SEGMENTS;
    }

    public function byArtist(): bool
    {
        return $this->mode === self::MODE_ARTIST;
    }

    public function wantsOpen(): bool
    {
        return $this->onsale === self::ONSALE_OPEN;
    }

    public function isFiltered(): bool
    {
        return $this->query !== '' || $this->city !== '' || $this->from !== null || $this->to !== null;
    }

    /** Pour reconstruire des liens : seuls les filtres non vides. @return array<string, string> */
    public function toQuery(): array
    {
        return array_filter([
            'q' => $this->query,
            'segment' => $this->segment === self::DEFAULT_SEGMENT ? '' : $this->segment,
            'city' => $this->city,
            'from' => $this->from?->format('Y-m-d') ?? '',
            'to' => $this->to?->format('Y-m-d') ?? '',
            'onsale' => $this->onsale === self::ONSALE_UPCOMING ? '' : $this->onsale,
            'mode' => $this->mode === self::MODE_ARTIST ? '' : $this->mode,
        ], static fn (string $v) => $v !== '');
    }
}
