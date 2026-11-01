<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\User;
use App\Event\EventCategory;
use App\Event\EventType;
use PHPUnit\Framework\TestCase;

final class EventTypeTest extends TestCase
{
    public function testEveryTypeHasACompleteCatalogueEntry(): void
    {
        foreach (EventType::cases() as $type) {
            self::assertNotSame('', $type->label());
            self::assertNotSame('', $type->emoji());
            self::assertStringStartsWith('un ', $type->noun());
            self::assertStringStartsWith('3 ', $type->plural(3));
            self::assertInstanceOf(EventCategory::class, $type->category());
        }
    }

    public function testCategoriesPartitionTheTypes(): void
    {
        $music = EventType::ofCategory(EventCategory::Music);
        $sport = EventType::ofCategory(EventCategory::Sport);

        self::assertSame(['concert', 'festival'], array_map(fn (EventType $t) => $t->value, $music));
        self::assertSame(['football', 'rugby', 'tennis', 'basket'], array_map(fn (EventType $t) => $t->value, $sport));
        self::assertCount(count(EventType::cases()), [...$music, ...$sport]);
    }

    public function testOnlyTeamSportsAreCollective(): void
    {
        self::assertSame(['football', 'rugby', 'basket'], User::collectiveSports());
        self::assertFalse(EventType::Tennis->isCollective());
        self::assertTrue(EventType::Tennis->isTennis());
    }

    public function testFavoriteTeamsOnlyKeepCollectiveSports(): void
    {
        $user = (new User())->setFavoriteTeams([
            'football' => '  ESTAC ',
            'tennis' => 'Nadal',
            'basket' => '',
            'curling' => 'X',
        ]);

        self::assertSame(['football' => 'ESTAC'], $user->getFavoriteTeams());
    }
}
