<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Stats\LuckyTeam;
use App\Tests\Support\Fixtures;
use PHPUnit\Framework\TestCase;

final class LuckyTeamTest extends TestCase
{
    public function testWinsDrawsLossesAndUnknownsAreCountedForTheFavouriteSide(): void
    {
        $parts = [
            Fixtures::participation(Fixtures::event('football', '2026-01-01', 'ESTAC vs PSG')->setFinalScore('2 - 1')),
            Fixtures::participation(Fixtures::event('football', '2026-01-08', 'OM vs ESTAC')->setFinalScore('3 - 0')),
            Fixtures::participation(Fixtures::event('football', '2026-01-15', 'ESTAC vs Lens')->setFinalScore('1 - 1')),
            Fixtures::participation(Fixtures::event('football', '2026-01-22', 'ESTAC vs Nice')),
            Fixtures::participation(Fixtures::event('rugby', '2026-01-22', 'ESTAC vs Nice')->setFinalScore('9 - 3')),
            Fixtures::participation(Fixtures::event('football', '2026-01-29', 'PSG vs OM')->setFinalScore('1 - 0')),
        ];

        $result = LuckyTeam::compute($parts, ['football' => 'estac', 'rugby' => '']);

        self::assertCount(1, $result['teams']);
        $team = $result['teams'][0];
        self::assertSame(4, $team['attended']);
        self::assertSame(1, $team['wins']);
        self::assertSame(1, $team['draws']);
        self::assertSame(1, $team['losses']);
        self::assertSame(1, $team['unknown']);
    }

    public function testATeamNeverSeenIsNotListed(): void
    {
        $parts = [Fixtures::participation(Fixtures::event('football', '2026-01-01', 'PSG vs OM')->setFinalScore('1 - 0'))];

        self::assertSame(['teams' => []], LuckyTeam::compute($parts, ['football' => 'ESTAC']));
    }
}
