<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Http\Input;
use PHPUnit\Framework\TestCase;

final class InputTest extends TestCase
{
    public function testDateAcceptsOnlyRealDatesInRange(): void
    {
        self::assertSame('2026-02-28', Input::date('2026-02-28')?->format('Y-m-d'));
        self::assertNull(Input::date('2026-02-30'), 'jour inexistant');
        self::assertNull(Input::date('28/02/2026'), 'mauvais format');
        self::assertNull(Input::date('1899-12-31'), 'trop ancien');
        self::assertNull(Input::date((new \DateTimeImmutable('+6 years'))->format('Y-m-d')), 'trop lointain');
        self::assertNull(Input::date(['2026-02-28']));
        self::assertNull(Input::date(null));
    }

    public function testTime(): void
    {
        self::assertSame('08:05', Input::time('08:05')?->format('H:i'));
        self::assertNull(Input::time('24:00'));
        self::assertNull(Input::time('8:05'));
        self::assertNull(Input::time(''));
    }

    public function testIntIsBounded(): void
    {
        self::assertSame(5, Input::int('5', 1, 5));
        self::assertSame(3, Input::int(3, 1, 5));
        self::assertNull(Input::int('6', 1, 5));
        self::assertNull(Input::int('0', 1, 5));
        self::assertNull(Input::int('', 1, 5));
        self::assertNull(Input::int('5.5', 1, 5));
        self::assertNull(Input::int('cinq', 1, 5));
    }

    public function testTextTrimsStripsControlCharsAndTruncates(): void
    {
        self::assertSame('Muse', Input::text("  Muse\x00\x07 ", 255));
        self::assertNull(Input::text('   ', 255));
        self::assertNull(Input::text(42, 255));
        self::assertSame('ééé', Input::text('éééééé', 3), 'tronqué en caractères, pas en octets');
        self::assertSame("ligne 1\nligne 2", Input::text("ligne 1\nligne 2", 100), 'les sauts de ligne restent');
    }

    public function testUuidAndStrings(): void
    {
        self::assertSame('019e8998-0b08-757d-80d5-b5d56e430825', Input::uuid('019e8998-0b08-757d-80d5-b5d56e430825'));
        self::assertNull(Input::uuid('not-a-uuid'));
        self::assertNull(Input::uuid(12));
        self::assertSame(['a', 'b'], Input::strings(['a', 1, 'b', null]));
        self::assertSame([], Input::strings('a'));
    }
}
