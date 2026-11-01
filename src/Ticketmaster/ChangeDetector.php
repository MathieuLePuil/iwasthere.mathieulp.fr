<?php

declare(strict_types=1);

namespace App\Ticketmaster;

/**
 * Compare l'état connu des événements suivis à ce que le flux en dit, et
 * nomme ce qui a changé. Pur.
 *
 *  - passé `cancelled`    → CANCELLED (la veille sera désactivée)
 *  - passé `rescheduled`  → RESCHEDULED (la veille est maintenue)
 *  - ouverture déplacée de plus d'une heure → DATE_CHANGED
 *
 * Un report qui déplace aussi l'ouverture ne produit qu'un RESCHEDULED : le
 * message donne la nouvelle date, inutile de doubler. Seules les transitions
 * comptent — un événement déjà annulé au moment de la veille n'alerte pas.
 */
final class ChangeDetector
{
    public const DATE_CHANGE_THRESHOLD = 3600;

    /**
     * @param array<string, array{status: string, onsale: ?string}> $snapshot event_id → état en base (EventWatchRepository::snapshotWatchedEvents)
     * @param array<string, array<string, mixed>>                   $rows     event_id → projection du flux
     *
     * @return array<string, WatchNotificationType> event_id → changement
     */
    public static function detect(array $snapshot, array $rows): array
    {
        $changes = [];
        foreach ($snapshot as $eventId => $known) {
            $row = $rows[$eventId] ?? null;
            if ($row === null) {
                continue;
            }

            $status = $row['status'];
            if ($status === 'cancelled') {
                if ($known['status'] !== 'cancelled') {
                    $changes[$eventId] = WatchNotificationType::Cancelled;
                }
                continue;
            }
            if ($status === 'rescheduled' && $known['status'] !== 'rescheduled') {
                $changes[$eventId] = WatchNotificationType::Rescheduled;
                continue;
            }

            $onsale = null;
            foreach ($row['windows'] as $window) {
                if ($window['type'] === SaleWindowType::Public->value) {
                    $onsale = $window['starts_at_utc'];
                }
            }
            if ($onsale === null || $known['onsale'] === null) {
                continue;
            }
            $delta = abs(strtotime($onsale . ' UTC') - strtotime($known['onsale'] . ' UTC'));
            if ($delta > self::DATE_CHANGE_THRESHOLD) {
                $changes[$eventId] = WatchNotificationType::DateChanged;
            }
        }

        return $changes;
    }
}
