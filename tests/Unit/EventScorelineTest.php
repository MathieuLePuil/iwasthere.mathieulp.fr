<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Tests\Support\Fixtures;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EventScorelineTest extends TestCase
{
    public function testNoScoreOrNoTeamsGivesNoScoreline(): void
    {
        $event = Fixtures::event('football', '2026-01-01', 'PSG vs OM');
        self::assertNull($event->getScoreline());

        $event->setFinalScore('2 - 1')->setTeams('PSG');
        self::assertNull($event->getScoreline());
    }

    #[DataProvider('plainScores')]
    public function testTeamSportsDeriveTheWinnerFromTheScore(string $score, ?int $winner): void
    {
        $event = Fixtures::event('football', '2026-01-01', 'PSG vs OM')->setFinalScore($score);
        $line = $event->getScoreline();

        self::assertSame('PSG', $line['team1']);
        self::assertSame('OM', $line['team2']);
        self::assertSame($winner, $line['winner']);
    }

    /** @return iterable<string, array{string, ?int}> */
    public static function plainScores(): iterable
    {
        yield 'home win' => ['3 - 1', 1];
        yield 'away win' => ['0-2', 2];
        yield 'draw' => ['1 - 1', null];
        yield 'unparseable' => ['abandonné', null];
    }

    public function testTennisNeedsAnExplicitWinner(): void
    {
        $event = Fixtures::event('tennis', '2026-06-01', 'Alcaraz vs Sinner')->setFinalScore('6/4 6/2');

        self::assertNull($event->getScoreline()['winner'], 'un score de tennis ne dit pas qui a gagné');

        $event->setWinner('2');
        self::assertSame(2, $event->getScoreline()['winner']);
    }

    public function testLegacyWinnerNamesAreMatchedCaseInsensitively(): void
    {
        $event = Fixtures::event('rugby', '2026-06-01', 'Toulouse vs Stade Français')
            ->setFinalScore('27 - 18')
            ->setWinner('stade français');

        self::assertSame(2, $event->getScoreline()['winner']);
    }

    public function testEditHistoryIsBoundedAndOrdered(): void
    {
        $event = Fixtures::event('concert', '2026-01-01', 'Muse');
        $by = Fixtures::user()->getId();
        for ($i = 0; $i < 60; ++$i) {
            $event->recordEdit($by, 'date', (string) $i, (string) ($i + 1));
        }

        $history = $event->getEditHistory();
        self::assertCount(50, $history);
        self::assertSame('60', end($history)['to']);
        self::assertSame((string) $by, $history[0]['by']);
    }
}
