<?php

declare(strict_types=1);

namespace App\Ticketmaster;

/**
 * Cycle de vie d'une échéance.
 *
 *  pending   → attend son heure (`scheduled_for`)
 *  queued    → prise par app:notifications:dispatch, poussée dans Messenger
 *  sent      → le handler a bien envoyé (push et/ou e-mail)
 *  logged    → mode « journalisation seule » : rien n'est parti, l'heure est consignée
 *  capped    → plafond journalier atteint, remplacée par le résumé du jour
 *  skipped   → rien à envoyer (pas d'URL actionnable, veille désactivée entre-temps…)
 *  cancelled → reprogrammée : la fenêtre a bougé ou l'option a été décochée
 *  failed    → le handler a échoué après ses tentatives
 */
enum WatchNotificationStatus: string
{
    case Pending = 'pending';
    case Queued = 'queued';
    case Sent = 'sent';
    case Logged = 'logged';
    case Capped = 'capped';
    case Skipped = 'skipped';
    case Cancelled = 'cancelled';
    case Failed = 'failed';

    /** Une échéance qui a « joué » : elle ne doit pas être replanifiée à l'identique. */
    public function isConsumed(): bool
    {
        return in_array($this, [self::Queued, self::Sent, self::Logged, self::Capped, self::Skipped], true);
    }
}
