<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ticketmaster;

use App\Ticketmaster\AlertPlanner;
use PHPUnit\Framework\TestCase;

final class AlertPlannerTest extends TestCase
{
    private const NOW = '2026-10-01 08:00:00';

    public function testJ1AndH1AreScheduledAheadOfAnOpeningFarAway(): void
    {
        $plan = $this->plan('2026-10-05 10:00:00', true, true);

        self::assertSame(['J1' => '2026-10-04 10:00:00', 'H1' => '2026-10-05 09:00:00'], $plan);
    }

    public function testOptionsCanBeDisabledIndependently(): void
    {
        self::assertSame(['J1' => '2026-10-04 10:00:00'], $this->plan('2026-10-05 10:00:00', true, false));
        self::assertSame(['H1' => '2026-10-05 09:00:00'], $this->plan('2026-10-05 10:00:00', false, true));
        self::assertSame([], $this->plan('2026-10-05 10:00:00', false, false));
    }

    public function testOpeningInLessThanADayButMoreThanTwoHoursGetsAnImminentJ1Now(): void
    {
        $plan = $this->plan('2026-10-01 14:00:00', true, true);

        self::assertSame(['J1' => self::NOW, 'H1' => '2026-10-01 13:00:00'], $plan);
        self::assertTrue(AlertPlanner::isImminent(new \DateTimeImmutable('2026-10-01 14:00:00'), new \DateTimeImmutable(self::NOW)));
        self::assertFalse(AlertPlanner::isImminent(new \DateTimeImmutable('2026-10-05 10:00:00'), new \DateTimeImmutable('2026-10-04 10:00:00')));
    }

    public function testOpeningInLessThanTwoHoursSkipsJ1AndKeepsH1(): void
    {
        self::assertSame(['H1' => '2026-10-01 08:30:00'], $this->plan('2026-10-01 09:30:00', true, true));
    }

    public function testOpeningInLessThanAnHourSendsH1Now(): void
    {
        self::assertSame(['H1' => self::NOW], $this->plan('2026-10-01 08:20:00', true, true));
    }

    public function testNothingIsPlannedOncePastTheOpening(): void
    {
        self::assertSame([], $this->plan('2026-10-01 08:00:00', true, true));
        self::assertSame([], $this->plan('2026-09-30 10:00:00', true, true));
    }

    public function testBoundariesAreInclusiveAtExactly24hAnd1h(): void
    {
        self::assertSame(['J1' => self::NOW, 'H1' => '2026-10-02 07:00:00'], $this->plan('2026-10-02 08:00:00', true, true));
        self::assertSame(['H1' => self::NOW], $this->plan('2026-10-01 09:00:00', false, true));
    }

    /** @return array<string, string> */
    private function plan(string $opensAt, bool $j1, bool $h1): array
    {
        $utc = new \DateTimeZone('UTC');
        $plan = AlertPlanner::plan(new \DateTimeImmutable($opensAt, $utc), new \DateTimeImmutable(self::NOW, $utc), $j1, $h1);

        return array_map(static fn (\DateTimeImmutable $d) => $d->format('Y-m-d H:i:s'), $plan);
    }
}
