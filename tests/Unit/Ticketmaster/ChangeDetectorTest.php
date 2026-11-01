<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ticketmaster;

use App\Ticketmaster\ChangeDetector;
use App\Ticketmaster\WatchNotificationType;
use PHPUnit\Framework\TestCase;

final class ChangeDetectorTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function row(string $status, string $onsale): array
    {
        return ['status' => $status, 'windows' => [['type' => 'public', 'starts_at_utc' => $onsale]]];
    }

    public function testDetectsTransitionsOnly(): void
    {
        $snapshot = [
            'a' => ['status' => 'onsale', 'onsale' => '2026-10-03 08:00:00'],
            'b' => ['status' => 'onsale', 'onsale' => '2026-10-03 08:00:00'],
            'c' => ['status' => 'cancelled', 'onsale' => '2026-10-03 08:00:00'],
            'd' => ['status' => 'onsale', 'onsale' => '2026-10-03 08:00:00'],
            'e' => ['status' => 'onsale', 'onsale' => '2026-10-03 08:00:00'],
            'f' => ['status' => 'onsale', 'onsale' => null],
            'gone' => ['status' => 'onsale', 'onsale' => '2026-10-03 08:00:00'],
        ];
        $rows = [
            'a' => self::row('cancelled', '2026-10-03 08:00:00'),
            'b' => self::row('rescheduled', '2026-10-10 08:00:00'),
            'c' => self::row('cancelled', '2026-10-03 08:00:00'),
            'd' => self::row('onsale', '2026-10-03 11:00:00'),
            'e' => self::row('onsale', '2026-10-03 08:59:00'),
            'f' => self::row('onsale', '2026-10-03 11:00:00'),
        ];

        $changes = ChangeDetector::detect($snapshot, $rows);

        self::assertSame([
            'a' => WatchNotificationType::Cancelled,
            'b' => WatchNotificationType::Rescheduled,
            'd' => WatchNotificationType::DateChanged,
        ], $changes);
    }

    public function testAnHourExactlyIsNotAChange(): void
    {
        $changes = ChangeDetector::detect(
            ['a' => ['status' => 'onsale', 'onsale' => '2026-10-03 08:00:00']],
            ['a' => self::row('onsale', '2026-10-03 09:00:00')],
        );

        self::assertSame([], $changes);
    }
}
