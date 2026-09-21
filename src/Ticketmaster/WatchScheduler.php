<?php

declare(strict_types=1);

namespace App\Ticketmaster;

use App\Entity\EventWatch;
use App\Entity\WatchNotification;
use App\Repository\EventWatchRepository;
use App\Repository\WatchNotificationRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Tient les échéances d'une veille en accord avec ses fenêtres de vente.
 *
 * Appelé à la création d'une veille, à chaque réglage, et après chaque
 * synchronisation pour toutes les veilles actives. Idempotent : replanifier
 * sans changement ne crée rien, et une ligne annulée que l'on retrouve à la
 * même heure est simplement ranimée — la contrainte d'unicité sur
 * (veille, fenêtre, type, heure) interdit de toute façon un doublon.
 *
 * Tolérances :
 *  - une échéance en attente à moins d'une minute de l'heure voulue est gardée ;
 *  - une échéance déjà partie à moins d'une heure de l'heure voulue vaut pour
 *    la nouvelle : une ouverture décalée de vingt minutes ne fait pas renvoyer
 *    la J-1. Au-delà d'une heure, c'est une reprogrammation (et le flux aura
 *    produit une notification DATE_CHANGED).
 */
final class WatchScheduler
{
    private const PENDING_TOLERANCE = 60;
    private const CONSUMED_TOLERANCE = 3600;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly WatchNotificationRepository $notifications,
        private readonly EventWatchRepository $watches,
    ) {}

    /**
     * Replanifie une veille. Ne flushe pas : l'appelant regroupe.
     *
     * @return array{created: int, cancelled: int}
     */
    public function reconcile(EventWatch $watch, \DateTimeImmutable $now): array
    {
        $existing = $this->notifications->findForWatch($watch);
        $stats = ['created' => 0, 'cancelled' => 0];
        $seenWindows = [];

        foreach ($watch->getEvent()->getSaleWindows() as $window) {
            $windowId = (string) $window->getId();
            $seenWindows[$windowId] = true;
            $plan = $watch->isActive()
                ? AlertPlanner::plan($window->getStartsAtUtc(), $now, $watch->isNotifyJ1(), $watch->isNotifyH1())
                : [];

            foreach ([WatchNotificationType::J1, WatchNotificationType::H1] as $type) {
                $rows = array_values(array_filter(
                    $existing,
                    static fn (WatchNotification $n) => $n->getType() === $type
                        && $n->getSaleWindow() !== null
                        && (string) $n->getSaleWindow()->getId() === $windowId,
                ));
                $planned = $plan[$type->value] ?? null;

                if ($planned === null) {
                    $stats['cancelled'] += $this->cancelPending($rows);
                    continue;
                }

                $keep = $this->find($rows, $planned, $now);
                if ($keep !== null) {
                    $stats['cancelled'] += $this->cancelPending($rows, $keep);
                    continue;
                }

                $stats['cancelled'] += $this->cancelPending($rows);
                $revivable = array_filter($rows, static fn (WatchNotification $n) => $n->getScheduledFor() == $planned);
                if ($revivable !== []) {
                    reset($revivable)->revive();
                } else {
                    $this->em->persist(new WatchNotification($watch, $window, $type, $planned));
                }
                $stats['created']++;
            }
        }

        // Une fenêtre disparue du flux (prévente retirée) emporte ses échéances
        foreach ($existing as $row) {
            if ($row->isPending() && $row->getSaleWindow() !== null && !isset($seenWindows[(string) $row->getSaleWindow()->getId()])) {
                $row->cancel();
                $stats['cancelled']++;
            }
        }

        return $stats;
    }

    /**
     * Toutes les veilles actives — après synchronisation.
     *
     * @return array{created: int, cancelled: int, watches: int}
     */
    public function reconcileAll(\DateTimeImmutable $now): array
    {
        $total = ['created' => 0, 'cancelled' => 0, 'watches' => 0];
        foreach ($this->watches->findActive() as $watch) {
            $stats = $this->reconcile($watch, $now);
            $total['created'] += $stats['created'];
            $total['cancelled'] += $stats['cancelled'];
            $total['watches']++;
        }
        $this->em->flush();

        return $total;
    }

    /**
     * Les changements constatés par la synchronisation sur des événements
     * suivis : une notification par veille active, à envoyer au prochain
     * passage du cron. Une annulation désactive la veille.
     *
     * @param array<string, WatchNotificationType> $changes event_id → nature du changement
     *
     * @return int notifications créées
     */
    public function applyChanges(array $changes, \DateTimeImmutable $now): int
    {
        $created = 0;
        foreach ($changes as $eventId => $type) {
            foreach ($this->watches->findActiveForEvent($eventId) as $watch) {
                $this->em->persist(new WatchNotification($watch, null, $type, $now));
                $created++;
                if ($type === WatchNotificationType::Cancelled) {
                    $watch->setActive(false);
                    $this->cancelPending($this->notifications->findForWatch($watch));
                }
            }
        }
        $this->em->flush();

        return $created;
    }

    /**
     * Une ligne existante qui vaut pour l'échéance voulue, s'il y en a une.
     *
     * @param list<WatchNotification> $rows
     */
    private function find(array $rows, \DateTimeImmutable $planned, \DateTimeImmutable $now): ?WatchNotification
    {
        foreach ($rows as $row) {
            $diff = abs($row->getScheduledFor()->getTimestamp() - $planned->getTimestamp());
            if ($row->getStatus()->isConsumed() && $diff <= self::CONSUMED_TOLERANCE) {
                return $row;
            }
            if ($row->isPending() && ($diff <= self::PENDING_TOLERANCE || ($row->getScheduledFor() <= $now && $planned <= $now))) {
                return $row;
            }
        }

        return null;
    }

    /** @param list<WatchNotification> $rows */
    private function cancelPending(array $rows, ?WatchNotification $except = null): int
    {
        $n = 0;
        foreach ($rows as $row) {
            if ($row !== $except && $row->isPending()) {
                $row->cancel();
                $n++;
            }
        }

        return $n;
    }
}
