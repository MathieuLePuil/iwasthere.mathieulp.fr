<?php

declare(strict_types=1);

namespace App\Ticketmaster;

use App\Entity\EventWatch;
use App\Entity\TmEvent;
use App\Entity\User;
use App\Repository\EventWatchRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Le cycle de vie d'une veille côté utilisateur (F4) : poser, régler, retirer.
 * Chaque changement replanifie les échéances dans la foulée — l'utilisateur
 * n'attend pas la prochaine synchronisation pour voir ses alertes calées.
 */
final class WatchService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly EventWatchRepository $watches,
        private readonly WatchScheduler $scheduler,
    ) {}

    /**
     * Suit l'événement — ou réactive une veille existante. Autorisé même si
     * l'ouverture est passée (l'interface le signale : aucune alerte ne partira).
     */
    public function follow(User $user, TmEvent $event, bool $j1 = true, bool $h1 = true, WatchChannel $channel = WatchChannel::Push): EventWatch
    {
        $watch = $this->watches->findOneForUserAndEvent($user, $event);
        if ($watch === null) {
            $watch = new EventWatch($user, $event);
            $this->em->persist($watch);
        }
        $watch->setNotifyJ1($j1)->setNotifyH1($h1)->setChannel($channel)->setActive(!$event->isCancelled());

        $this->scheduler->reconcile($watch, new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
        $this->em->flush();

        return $watch;
    }

    public function update(EventWatch $watch, bool $j1, bool $h1, WatchChannel $channel): void
    {
        $watch->setNotifyJ1($j1)->setNotifyH1($h1)->setChannel($channel);
        $this->scheduler->reconcile($watch, new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
        $this->em->flush();
    }

    /** Efface la veille et, en cascade, ses échéances. */
    public function remove(EventWatch $watch): void
    {
        $this->em->remove($watch);
        $this->em->flush();
    }
}
