<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Stats\FestivalEditions;
use App\Tests\Support\Fixtures;
use PHPUnit\Framework\TestCase;

final class FestivalEditionsTest extends TestCase
{
    public function testConsecutiveDaysAtTheSameVenueFormOneEdition(): void
    {
        $hellfest = Fixtures::venue('Hellfest');
        $parts = [
            Fixtures::participation(Fixtures::event('festival', '2025-06-20', 'Gojira', $hellfest)),
            Fixtures::participation(Fixtures::event('festival', '2025-06-22', 'Muse', $hellfest)),
            Fixtures::participation(Fixtures::event('festival', '2024-06-21', 'Metallica', $hellfest)),
            Fixtures::participation(Fixtures::event('concert', '2025-06-21', 'Pas un festival', $hellfest)),
        ];

        $editions = FestivalEditions::group($parts);

        self::assertCount(2, $editions);
        self::assertSame(2024, FestivalEditions::year($editions[0]));
        self::assertSame(2025, FestivalEditions::year($editions[1]));
        self::assertCount(2, $editions[1]);
        self::assertSame('Hellfest', FestivalEditions::name($editions[1]));
    }

    public function testDifferentVenuesAreDifferentEditions(): void
    {
        $parts = [
            Fixtures::participation(Fixtures::event('festival', '2025-07-01', 'A', Fixtures::venue('Rock en Seine'))),
            Fixtures::participation(Fixtures::event('festival', '2025-07-01', 'B', Fixtures::venue('Vieilles Charrues'))),
        ];

        self::assertCount(2, FestivalEditions::group($parts));
    }
}
