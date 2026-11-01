<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ticketmaster;

use App\Ticketmaster\ArtistAnnouncementMatcher;
use App\Ticketmaster\ArtistAnnouncer;
use PHPUnit\Framework\TestCase;

final class ArtistAnnouncementMatcherTest extends TestCase
{
    private const NOW = '2026-09-21 12:00:00';

    /** @return array<string, mixed> */
    private static function row(string $id, string $name, ?string $artists, string $venue, ?string $date, string $status = 'onsale', ?string $onsale = '2026-10-03 08:00:00'): array
    {
        return [
            'event_id' => $id,
            'name' => $name,
            'status' => $status,
            'artists_normalized' => $artists,
            'venue_name' => $venue,
            'venue_city' => 'Paris',
            'event_start_local_date' => $date,
            'url' => 'https://www.ticketmaster.fr/x/' . $id,
            'windows' => $onsale === null ? [] : [['type' => 'public', 'starts_at_utc' => $onsale]],
        ];
    }

    private static function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable(self::NOW, new \DateTimeZone('UTC'));
    }

    public function testMatchesWholeArtistNameOnly(): void
    {
        $rows = [
            'a' => self::row('a', 'MUSE', ' | muse | ', 'Accor Arena', '2026-11-27'),
            'b' => self::row('b', 'Muse by Soaked', ' | muse by soaked | ', 'Le Trianon', '2026-11-28'),
            'c' => self::row('c', 'Musée Grévin', null, 'Musée Grévin', '2026-11-29'),
        ];

        $result = ArtistAnnouncementMatcher::match($rows, ['u1' => ['muse' => 'Muse']], self::now());

        self::assertSame(['u1'], array_keys($result));
        self::assertSame(['muse'], array_keys($result['u1']));
        self::assertSame('Muse', $result['u1']['muse']['artist']);
        self::assertCount(1, $result['u1']['muse']['concerts']);
        self::assertSame('Accor Arena', $result['u1']['muse']['concerts'][0]['venue']);
    }

    public function testOffersOfTheSameConcertCollapseToTheShortestName(): void
    {
        $rows = [
            'a' => self::row('a', 'PRESTATION HOSPITALITE MUSE', ' | muse | ', 'Paris La Défense Arena', '2026-11-27'),
            'b' => self::row('b', 'MUSE', ' | muse | ', 'Paris La Défense Arena', '2026-11-27'),
            'c' => self::row('c', 'MUSE - LOGES', ' | muse | ', 'Paris La Défense Arena', '2026-11-27'),
            'd' => self::row('d', 'MUSE', ' | muse | ', 'Halle Tony Garnier', '2026-11-29'),
        ];

        $result = ArtistAnnouncementMatcher::match($rows, ['u1' => ['muse' => 'Muse']], self::now());

        $concerts = $result['u1']['muse']['concerts'];
        self::assertCount(2, $concerts);
        self::assertSame(['MUSE', 'MUSE'], array_column($concerts, 'name'));
        self::assertSame(['2026-11-27', '2026-11-29'], array_column($concerts, 'date'), 'par date croissante');
    }

    public function testSkipsCancelledAndPastConcerts(): void
    {
        $rows = [
            'a' => self::row('a', 'MUSE', ' | muse | ', 'Accor Arena', '2026-11-27', 'cancelled'),
            'b' => self::row('b', 'MUSE', ' | muse | ', 'Le Zénith', '2026-09-20'),
            'c' => self::row('c', 'MUSE', ' | muse | ', 'Olympia', null),
        ];

        $result = ArtistAnnouncementMatcher::match($rows, ['u1' => ['muse' => 'Muse']], self::now());

        self::assertCount(1, $result['u1']['muse']['concerts']);
        self::assertSame('Olympia', $result['u1']['muse']['concerts'][0]['venue'], 'sans date : on ne peut pas dire qu\'il est passé');
    }

    public function testMultiArtistEventReachesEachInterestedUser(): void
    {
        $rows = ['a' => self::row('a', 'INDOCHINE + JEAN-LOUIS AUBERT', ' | indochine | jean louis aubert | ', 'Stade de France', '2027-06-12')];
        $interests = [
            'u1' => ['indochine' => 'Indochine'],
            'u2' => ['jean louis aubert' => 'Jean-Louis Aubert', 'muse' => 'Muse'],
            'u3' => ['pnl' => 'PNL'],
        ];

        $result = ArtistAnnouncementMatcher::match($rows, $interests, self::now());

        self::assertSame(['u1', 'u2'], array_keys($result));
        self::assertSame(['jean louis aubert'], array_keys($result['u2']), 'pas d\'annonce vide pour Muse');
    }

    public function testNothingWhenNoRowsOrNoInterests(): void
    {
        self::assertSame([], ArtistAnnouncementMatcher::match([], ['u1' => ['muse' => 'Muse']], self::now()));
        self::assertSame([], ArtistAnnouncementMatcher::match(['a' => self::row('a', 'MUSE', ' | muse | ', 'Olympia', null)], [], self::now()));
    }

    public function testDedupeKeyDependsOnConcertSetNotOrder(): void
    {
        $a = ['venue' => 'Olympia', 'date' => '2026-11-27'];
        $b = ['venue' => 'Le Zénith', 'date' => '2026-11-29'];

        self::assertSame(ArtistAnnouncementMatcher::dedupeKey('muse', [$a, $b]), ArtistAnnouncementMatcher::dedupeKey('muse', [$b, $a]));
        self::assertNotSame(ArtistAnnouncementMatcher::dedupeKey('muse', [$a]), ArtistAnnouncementMatcher::dedupeKey('muse', [$a, $b]), 'une date de plus est une annonce nouvelle');
        self::assertNotSame(ArtistAnnouncementMatcher::dedupeKey('muse', [$a]), ArtistAnnouncementMatcher::dedupeKey('u2', [$a]));
        self::assertLessThanOrEqual(120, strlen(ArtistAnnouncementMatcher::dedupeKey('muse', [$a, $b])), 'tient dans notification.dedupe_key');
    }

    public function testComposeListsThreeDatesThenCounts(): void
    {
        $concerts = [
            ['name' => 'MUSE', 'venue' => 'Paris La Défense Arena', 'city' => 'Nanterre', 'date' => '2026-11-27', 'url' => null, 'onsale' => null],
            ['name' => 'MUSE', 'venue' => 'Halle Tony Garnier', 'city' => 'Lyon', 'date' => '2026-11-29', 'url' => null, 'onsale' => null],
            ['name' => 'MUSE', 'venue' => 'Zénith', 'city' => null, 'date' => null, 'url' => null, 'onsale' => null],
            ['name' => 'MUSE', 'venue' => 'Arkéa Arena', 'city' => 'Bordeaux', 'date' => '2027-01-05', 'url' => null, 'onsale' => null],
            ['name' => 'MUSE', 'venue' => 'Sud de France Arena', 'city' => 'Montpellier', 'date' => '2027-01-07', 'url' => null, 'onsale' => null],
        ];

        $text = ArtistAnnouncer::compose(['artist' => 'Muse', 'concerts' => $concerts], self::now());

        self::assertSame('Muse annonce 5 dates en France', $text['title']);
        self::assertSame('Nanterre · ven. 27 nov., Lyon · dim. 29 nov., Zénith et 2 autres — pose une veille pour être prévenu de l\'ouverture.', $text['body']);

        $single = ArtistAnnouncer::compose(['artist' => 'Muse', 'concerts' => [$concerts[0]]], self::now());
        self::assertSame('Muse annonce une date en France', $single['title']);
        self::assertSame('Nanterre · ven. 27 nov. — pose une veille pour être prévenu de l\'ouverture.', $single['body']);
    }
}
