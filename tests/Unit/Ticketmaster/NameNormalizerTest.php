<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ticketmaster;

use App\Ticketmaster\NameNormalizer;
use PHPUnit\Framework\TestCase;

final class NameNormalizerTest extends TestCase
{
    public function testNormalizeDropsAccentsCaseAndPunctuation(): void
    {
        self::assertSame('indochine central tour indochine', NameNormalizer::normalize('INDOCHINE - CENTRAL TOUR - INDOCHINE'));
        self::assertSame('etienne daho l olympia', NameNormalizer::normalize("Étienne Daho (L'Olympia)"));
        self::assertSame('zaho de sagazan', NameNormalizer::normalize('  Zaho   de Sagazan  '));
        self::assertSame('', NameNormalizer::normalize('***'));
    }

    public function testBooleanQueryRequiresEachWordAsPrefixAndSkipsShortOnes(): void
    {
        self::assertSame('+indo* +central*', NameNormalizer::booleanQuery('Indo central'));
        self::assertSame('', NameNormalizer::booleanQuery('U2'), 'un mot trop court pour l\'index ne produit rien : le LIKE prend le relais');
        self::assertSame('+daho*', NameNormalizer::booleanQuery('É. Daho'));
    }
}
