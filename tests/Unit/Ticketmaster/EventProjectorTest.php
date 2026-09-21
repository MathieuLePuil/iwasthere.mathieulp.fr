<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ticketmaster;

use App\Ticketmaster\EventProjector;
use PHPUnit\Framework\TestCase;

final class EventProjectorTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function raw(array $overrides = []): array
    {
        return $overrides + [
            'eventId' => 'Z698xZC2Z17abcd',
            'eventName' => 'INDOCHINE - CENTRAL TOUR - INDOCHINE',
            'eventStatus' => 'onsale',
            'onsaleStartDateTime' => '2026-10-03T08:00:00Z',
            'apiOnsaleStartDateTime' => '2026-10-03T09:00:00Z',
            'onsaleEndDateTime' => '2027-03-14T18:00:00Z',
            'eventStartDateTime' => '2027-03-14T19:00:00Z',
            'eventStartLocalDate' => '2027-03-14',
            'eventStartLocalTime' => '20:00:00',
            'primaryEventUrl' => 'https://www.ticketmaster.fr/fr/manifestation/indochine/1',
            'venue' => ['venueName' => 'Accor Arena', 'venueCity' => 'Paris', 'venueTimezone' => 'Europe/Paris'],
            'classificationSegment' => 'Music',
            'classificationGenre' => 'Rock',
            'source' => 'tmr',
            'presales' => [],
            'attractions' => [
                ['attraction' => ['attractionId' => 'K8vZ9171', 'attractionName' => 'Indochine']],
                ['attraction' => ['attractionId' => 'K8vZ9172', 'attractionName' => 'Jean-Louis Aubert']],
            ],
            'resaleEventUrl' => null,
            'transactable' => false,
            'hotEvent' => false,
        ];
    }

    public function testProjectsTheRetainedFieldsAndThePublicSaleWindow(): void
    {
        $row = EventProjector::project(self::raw(), ['Music']);

        self::assertNotNull($row);
        self::assertSame('Z698xZC2Z17abcd', $row['event_id']);
        self::assertSame('indochine central tour indochine', $row['name_normalized']);
        self::assertSame('2027-03-14 19:00:00', $row['event_start_utc']);
        self::assertSame('2027-03-14', $row['event_start_local_date']);
        self::assertSame('20:00:00', $row['event_start_local_time']);
        self::assertSame('Paris', $row['venue_city']);
        self::assertSame('Europe/Paris', $row['venue_timezone']);
        self::assertCount(1, $row['windows']);
        self::assertSame('public', $row['windows'][0]['type']);
        self::assertSame('2026-10-03 08:00:00', $row['windows'][0]['starts_at_utc'], 'onsaleStartDateTime, jamais apiOnsaleStartDateTime (+1 h partout)');
        self::assertSame('2027-03-14 18:00:00', $row['windows'][0]['ends_at_utc']);
        self::assertSame(32, strlen($row['payload_hash']));
    }

    public function testArtistsComeFromAttractions(): void
    {
        $row = EventProjector::project(self::raw(), null);

        self::assertSame('Indochine', $row['artist_name']);
        self::assertSame(' | indochine | jean louis aubert | ', $row['artists_normalized']);

        $none = EventProjector::project(self::raw(['attractions' => []]), null);
        self::assertNull($none['artist_name']);
        self::assertNull($none['artists_normalized']);
    }

    public function testOnsaleIsConvertedToUtcWhateverTheOffset(): void
    {
        $row = EventProjector::project(self::raw(['onsaleStartDateTime' => '2026-10-03T10:00:00+02:00']), null);

        self::assertSame('2026-10-03 08:00:00', $row['windows'][0]['starts_at_utc']);
    }

    public function testSegmentsFilterAndNullMeansEverything(): void
    {
        self::assertNull(EventProjector::project(self::raw(['classificationSegment' => 'Arts & Theatre']), ['Music']));
        self::assertNotNull(EventProjector::project(self::raw(['classificationSegment' => 'Arts & Theatre']), ['Music', 'Arts & Theatre']));
        self::assertNotNull(EventProjector::project(self::raw(['classificationSegment' => 'Sports']), null));
    }

    public function testUnusableEventsAreDropped(): void
    {
        self::assertNull(EventProjector::project(self::raw(['eventId' => '']), null), 'sans id');
        self::assertNull(EventProjector::project(self::raw(['eventName' => null]), null), 'sans nom');
        self::assertNull(EventProjector::project(self::raw(['onsaleStartDateTime' => null]), null), 'sans ouverture : rien à planifier');
        self::assertNull(EventProjector::project(self::raw(['onsaleStartDateTime' => 'pas une date']), null));
    }

    public function testHashChangesWithTheOnsaleDateAndNotWithIgnoredFields(): void
    {
        $base = EventProjector::project(self::raw(), null);
        $sameWithNoise = EventProjector::project(self::raw(['apiOnsaleStartDateTime' => '2000-01-01T00:00:00Z', 'hotEvent' => true]), null);
        $moved = EventProjector::project(self::raw(['onsaleStartDateTime' => '2026-10-03T09:00:00Z']), null);

        self::assertSame($base['payload_hash'], $sameWithNoise['payload_hash']);
        self::assertNotSame($base['payload_hash'], $moved['payload_hash']);
    }

    public function testPresalesBecomeExtraWindowsWhenTheFeedEverProvidesThem(): void
    {
        $row = EventProjector::project(self::raw(['presales' => [
            ['name' => 'Prévente fan club', 'startDateTime' => '2026-10-01T08:00:00Z', 'endDateTime' => '2026-10-02T22:00:00Z', 'url' => 'https://example.test/presale'],
            ['name' => 'Cassée', 'startDateTime' => null],
        ]]), null);

        self::assertCount(2, $row['windows']);
        self::assertSame('presale', $row['windows'][1]['type']);
        self::assertSame('Prévente fan club', $row['windows'][1]['label']);
        self::assertSame('2026-10-01 08:00:00', $row['windows'][1]['starts_at_utc']);
    }

    public function testMissingTimeAndUnknownTimezoneAreTolerated(): void
    {
        $row = EventProjector::project(self::raw([
            'eventStartDateTime' => null,
            'eventStartLocalTime' => null,
            'venue' => ['venueName' => 'Le Bikini', 'venueCity' => 'Toulouse', 'venueTimezone' => 'Mars/Olympus'],
        ]), null);

        self::assertNull($row['event_start_utc']);
        self::assertNull($row['event_start_local_time']);
        self::assertNull($row['venue_timezone']);
        self::assertSame('2027-03-14', $row['event_start_local_date']);
    }
}
