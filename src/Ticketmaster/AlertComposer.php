<?php

declare(strict_types=1);

namespace App\Ticketmaster;

use App\Entity\TmEvent;
use App\Entity\WatchNotification;
use App\Notification\NotificationType;

/**
 * Rédige une alerte à partir d'un lot d'échéances du même type, pour le même
 * utilisateur. Un lot d'un seul élément mène droit à la page Ticketmaster ;
 * un lot de plusieurs (beaucoup d'ouvertures tombent à 10:00) fait une seule
 * notification qui les liste et mène à la liste des veilles.
 *
 * Pur : ni base, ni horloge autre que le `$now` reçu.
 */
final class AlertComposer
{
    public const WATCH_LIST_URL = '/alerts';

    /**
     * @param list<WatchNotification> $items même utilisateur, même type, chacun avec une URL
     *
     * @return array{title: string, body: string, url: string, type: NotificationType, items: list<array{title: string, body: string, url: string}>}
     */
    public static function compose(array $items, \DateTimeImmutable $now): array
    {
        if ($items === []) {
            throw new \InvalidArgumentException('Rien à composer.');
        }

        $type = $items[0]->getType();
        $lines = array_map(static fn (WatchNotification $n) => self::single($n, $now), $items);

        if (count($items) === 1) {
            return ['title' => $lines[0]['title'], 'body' => $lines[0]['body'], 'url' => $lines[0]['url'], 'type' => self::notificationType($type), 'items' => $lines];
        }

        $n = count($items);
        $names = implode(', ', array_map(static fn (WatchNotification $i) => self::shortName($i), $items));
        [$title, $body] = match ($type) {
            WatchNotificationType::J1 => [sprintf('%d billetteries ouvrent bientôt', $n), $names],
            WatchNotificationType::H1 => [sprintf('%d billetteries ouvrent dans 1 h', $n), $names],
            WatchNotificationType::Cancelled => [sprintf('%d événements suivis annulés', $n), $names],
            WatchNotificationType::Rescheduled => [sprintf('%d événements suivis reportés', $n), $names],
            WatchNotificationType::DateChanged => [sprintf('%d ouvertures déplacées', $n), $names],
        };

        return ['title' => $title, 'body' => $body, 'url' => self::WATCH_LIST_URL, 'type' => self::notificationType($type), 'items' => $lines];
    }

    public static function notificationType(WatchNotificationType $type): NotificationType
    {
        return $type->isOnsaleAlert() ? NotificationType::TicketOnsale : NotificationType::TicketEventChange;
    }

    /** @return array{title: string, body: string, url: string} */
    public static function single(WatchNotification $n, \DateTimeImmutable $now): array
    {
        $event = $n->getWatch()->getEvent();
        $window = $n->getSaleWindow() ?? $event->getPublicSaleWindow();
        $name = $event->getName();
        $url = $window?->getUrl() ?? $event->getUrl() ?? self::WATCH_LIST_URL;

        $context = array_filter([
            $event->getPlace(),
            self::concertDate($event, $now),
        ]);

        switch ($n->getType()) {
            case WatchNotificationType::J1:
                $opens = $window?->getStartsAtUtc();
                if ($opens !== null && AlertPlanner::isImminent($opens, $n->getScheduledFor())) {
                    $title = 'Ouverture imminente : ' . $name;
                    $context[] = sprintf('billetterie %s (%s)', ParisTime::dayAndTime($opens, $now), ParisTime::in($opens, $now));
                } else {
                    $title = 'Billetterie demain : ' . $name;
                    if ($opens !== null) {
                        $context[] = 'ouverture ' . ParisTime::dayAndTime($opens, $now);
                    }
                }

                return ['title' => $title, 'body' => implode(' · ', $context), 'url' => $url];

            case WatchNotificationType::H1:
                $opens = $window?->getStartsAtUtc();
                if ($opens !== null) {
                    $context[] = 'ouverture à ' . ParisTime::time($opens);
                }
                $context[] = 'la page Ticketmaster affiche un compte à rebours et redirige vers la réservation';

                return ['title' => 'Billetterie dans 1 h : ' . $name, 'body' => implode(' · ', $context), 'url' => $url];

            case WatchNotificationType::Cancelled:
                return [
                    'title' => 'Annulé : ' . $name,
                    'body' => implode(' · ', [...$context, 'l\'événement est annulé sur Ticketmaster, ta veille est désactivée']),
                    'url' => $url,
                ];

            case WatchNotificationType::Rescheduled:
                $body = [...$context, 'reporté par Ticketmaster, ta veille est maintenue'];
                if ($window !== null) {
                    $body[] = 'ouverture ' . ParisTime::dayAndTime($window->getStartsAtUtc(), $now);
                }

                return ['title' => 'Reporté : ' . $name, 'body' => implode(' · ', $body), 'url' => $url];

            case WatchNotificationType::DateChanged:
                $body = $context;
                if ($window !== null) {
                    $body[] = 'nouvelle ouverture ' . ParisTime::dayAndTime($window->getStartsAtUtc(), $now);
                }
                $body[] = 'tes alertes ont été reprogrammées';

                return ['title' => 'Ouverture déplacée : ' . $name, 'body' => implode(' · ', $body), 'url' => $url];
        }
    }

    private static function shortName(WatchNotification $n): string
    {
        $event = $n->getWatch()->getEvent();
        $window = $n->getSaleWindow();
        $name = mb_strimwidth($event->getName(), 0, 40, '…');

        return $window === null ? $name : sprintf('%s (%s)', $name, ParisTime::time($window->getStartsAtUtc()));
    }

    private static function concertDate(TmEvent $event, \DateTimeImmutable $now): ?string
    {
        $start = $event->getEventStartLocal();
        if ($start === null) {
            return null;
        }
        $label = 'concert le ' . ParisTime::dayLocal($start, $now);

        return $event->hasEventStartTime() ? $label . ' à ' . $start->format('G\hi') : $label;
    }
}
