<?php

declare(strict_types=1);

namespace App\Ticketmaster;

/** Canal principal d'une veille. Le push retombe sur l'e-mail sans accusé de réception sous 5 minutes. */
enum WatchChannel: string
{
    case Push = 'push';
    case Email = 'email';

    public function label(): string
    {
        return match ($this) {
            self::Push => 'Push (e-mail en secours)',
            self::Email => 'E-mail uniquement',
        };
    }
}
