<?php

declare(strict_types=1);

namespace App\Event;

/**
 * Le catalogue des types d'événements — le seul endroit qui les liste.
 *
 * La valeur d'un cas est stockée telle quelle dans event.type et sert de
 * suffixe aux classes CSS (poster-{type}, iwt-hero-art-tag--{type},
 * --type-{type}) : ne pas la renommer sans migration ni passage dans app.css.
 *
 * Tout ce que les templates et services savaient chacun de leur côté
 * (emoji, libellé, phrase du feed, sport collectif ou non) vit ici : le
 * basket avait été ajouté au formulaire sans l'être aux huit autres endroits.
 */
enum EventType: string
{
    case Concert = 'concert';
    case Festival = 'festival';
    case Football = 'football';
    case Rugby = 'rugby';
    case Tennis = 'tennis';
    case Basket = 'basket';

    public function category(): EventCategory
    {
        return match ($this) {
            self::Concert, self::Festival => EventCategory::Music,
            default => EventCategory::Sport,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Concert => 'Concert',
            self::Festival => 'Festival',
            self::Football => 'Football',
            self::Rugby => 'Rugby',
            self::Tennis => 'Tennis',
            self::Basket => 'Basket',
        };
    }

    public function emoji(): string
    {
        return match ($this) {
            self::Concert => '🎤',
            self::Festival => '🎪',
            self::Football => '⚽',
            self::Rugby => '🏉',
            self::Tennis => '🎾',
            self::Basket => '🏀',
        };
    }

    /** « ⚽ Football » — la forme des filtres et des pastilles. */
    public function labelWithEmoji(): string
    {
        return $this->emoji() . ' ' . $this->label();
    }

    /** « un match de foot », pour les phrases qui parlent d'un seul événement. */
    public function noun(): string
    {
        return match ($this) {
            self::Concert => 'un concert',
            self::Festival => 'un festival',
            self::Football => 'un match de foot',
            self::Rugby => 'un match de rugby',
            self::Tennis => 'un match de tennis',
            self::Basket => 'un match de basket',
        };
    }

    /** « 3 matchs de foot » — le pluriel du feed et du Rewind. */
    public function plural(int $count): string
    {
        $noun = match ($this) {
            self::Concert => 'concerts',
            self::Festival => 'festivals',
            self::Football => 'matchs de foot',
            self::Rugby => 'matchs de rugby',
            self::Tennis => 'matchs de tennis',
            self::Basket => 'matchs de basket',
        };

        return $count . ' ' . $noun;
    }

    /** Un sport d'équipe : deux camps, un score « A - B », une équipe porte-bonheur possible. */
    public function isCollective(): bool
    {
        return in_array($this, [self::Football, self::Rugby, self::Basket], true);
    }

    /** Les scores de tennis se lisent par sets et ne disent pas qui a gagné (voir Event::getScoreline). */
    public function isTennis(): bool
    {
        return $this === self::Tennis;
    }

    /** @return list<self> */
    public static function ofCategory(EventCategory $category): array
    {
        return array_values(array_filter(self::cases(), fn (self $t) => $t->category() === $category));
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $t) => $t->value, self::cases());
    }
}
