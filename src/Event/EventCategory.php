<?php

declare(strict_types=1);

namespace App\Event;

enum EventCategory: string
{
    case Music = 'music';
    case Sport = 'sport';

    public function label(): string
    {
        return match ($this) {
            self::Music => 'Musique',
            self::Sport => 'Sport',
        };
    }

    /** Le type proposé par défaut quand on ouvre le formulaire de cette catégorie. */
    public function defaultType(): EventType
    {
        return match ($this) {
            self::Music => EventType::Concert,
            self::Sport => EventType::Football,
        };
    }
}
