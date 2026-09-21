<?php

declare(strict_types=1);

namespace App\Ticketmaster;

/**
 * Ce qu'une échéance de veille annonce.
 *
 * J1 et H1 sont planifiées sur une fenêtre de vente ; les trois autres
 * naissent d'un changement constaté à la synchronisation et partent au
 * prochain passage du cron d'envoi.
 */
enum WatchNotificationType: string
{
    case J1 = 'J1';
    case H1 = 'H1';
    case Cancelled = 'CANCELLED';
    case Rescheduled = 'RESCHEDULED';
    case DateChanged = 'DATE_CHANGED';

    /** Les deux alertes planifiées sur l'ouverture de billetterie. */
    public function isOnsaleAlert(): bool
    {
        return $this === self::J1 || $this === self::H1;
    }
}
