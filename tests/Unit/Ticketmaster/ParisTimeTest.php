<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ticketmaster;

use App\Ticketmaster\ParisTime;
use PHPUnit\Framework\TestCase;

final class ParisTimeTest extends TestCase
{
    public function testFormatsInParisTimeAcrossTheDstChange(): void
    {
        $now = new \DateTimeImmutable('2026-09-19 08:00:00', new \DateTimeZone('UTC'));

        // Heure d'été : UTC+2
        self::assertSame('sam. 3 oct. à 10h00', ParisTime::dayAndTime(new \DateTimeImmutable('2026-10-03 08:00:00', new \DateTimeZone('UTC')), $now));
        // Heure d'hiver, après le 25 octobre 2026 : UTC+1 — jamais un décalage fixe
        self::assertSame('sam. 7 nov. à 10h00', ParisTime::dayAndTime(new \DateTimeImmutable('2026-11-07 09:00:00', new \DateTimeZone('UTC')), $now));
        // L'année n'apparaît que si elle diffère de l'année courante
        self::assertSame('ven. 15 janv. 2027 à 10h00', ParisTime::dayAndTime(new \DateTimeImmutable('2027-01-15 09:00:00', new \DateTimeZone('UTC')), $now));
    }

    public function testRelativeDelay(): void
    {
        $now = new \DateTimeImmutable('2026-09-19 08:00:00', new \DateTimeZone('UTC'));

        self::assertSame('dans 45 min', ParisTime::in(new \DateTimeImmutable('2026-09-19 08:45:00', new \DateTimeZone('UTC')), $now));
        self::assertSame('dans 3 h', ParisTime::in(new \DateTimeImmutable('2026-09-19 11:00:00', new \DateTimeZone('UTC')), $now));
        self::assertSame('dans 3 h 05', ParisTime::in(new \DateTimeImmutable('2026-09-19 11:05:00', new \DateTimeZone('UTC')), $now));
    }

    public function testStartOfParisDayIsReturnedAsUtc(): void
    {
        $start = ParisTime::startOfDay(new \DateTimeImmutable('2026-09-19 23:30:00', new \DateTimeZone('UTC')));

        self::assertSame('2026-09-19 22:00:00 UTC', $start->format('Y-m-d H:i:s e'), 'minuit le 20 à Paris = 22:00 UTC le 19');
    }
}
