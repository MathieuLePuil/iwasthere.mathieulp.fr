<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Reaction\ReactionEmoji;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ReactionEmojiTest extends TestCase
{
    #[DataProvider('inputs')]
    public function testNormalize(string $input, ?string $expected): void
    {
        self::assertSame($expected, ReactionEmoji::normalize($input));
    }

    /** @return iterable<string, array{string, ?string}> */
    public static function inputs(): iterable
    {
        yield 'simple' => ['🔥', '🔥'];
        yield 'trimmed' => [' 🎉 ', '🎉'];
        yield 'with variation selector' => ['❤️', '❤️'];
        yield 'flag' => ['🇫🇷', '🇫🇷'];
        yield 'skin tone' => ['👏🏽', '👏🏽'];
        yield 'two emojis' => ['🔥🔥', null];
        yield 'letter' => ['a', null];
        yield 'digit' => ['7', null];
        yield 'empty' => ['', null];
        yield 'word' => ['bravo', null];
    }

    public function testSuggestionsAreAllValid(): void
    {
        foreach (ReactionEmoji::SUGGESTIONS as $emoji) {
            self::assertSame($emoji, ReactionEmoji::normalize($emoji));
        }
    }
}
