<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ticketmaster;

use App\Entity\EventWatch;
use App\Entity\WatchNotification;
use App\Repository\EventWatchRepository;
use App\Repository\WatchNotificationRepository;
use App\Tests\Support\Fixtures;
use App\Ticketmaster\WatchNotificationStatus;
use App\Ticketmaster\WatchNotificationType;
use App\Ticketmaster\WatchScheduler;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Le repository est doublé : il rend les échéances qu'on lui a données, et
 * l'EntityManager capture ce qui est persisté. Pas de base.
 */
final class WatchSchedulerTest extends TestCase
{
    /** @var list<WatchNotification> */
    private array $persisted = [];

    /** @var list<WatchNotification> */
    private array $existing = [];

    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->now = new \DateTimeImmutable('2026-10-01 08:00:00', new \DateTimeZone('UTC'));
    }

    private function scheduler(): WatchScheduler
    {
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(function (object $entity): void {
            if ($entity instanceof WatchNotification) {
                $this->persisted[] = $entity;
            }
        });
        $notifications = $this->createStub(WatchNotificationRepository::class);
        $notifications->method('findForWatch')->willReturnCallback(fn () => $this->existing);

        return new WatchScheduler($em, $notifications, $this->createStub(EventWatchRepository::class));
    }

    private function watch(string $onsaleUtc): EventWatch
    {
        return Fixtures::watch(Fixtures::user(), Fixtures::tmEvent('evt', 'Indochine', $onsaleUtc));
    }

    private function existing(EventWatch $watch, WatchNotificationType $type, string $scheduledUtc, WatchNotificationStatus $status = WatchNotificationStatus::Pending): WatchNotification
    {
        $n = new WatchNotification($watch, $watch->getEvent()->getPublicSaleWindow(), $type, new \DateTimeImmutable($scheduledUtc, new \DateTimeZone('UTC')));
        match ($status) {
            WatchNotificationStatus::Pending => null,
            WatchNotificationStatus::Cancelled => $n->cancel(),
            WatchNotificationStatus::Sent => $n->markQueued(Uuid::v7(), $this->now)->markSent(),
            default => $n->close($status, $this->now),
        };
        $this->existing[] = $n;

        return $n;
    }

    /** @return list<string> */
    private function persistedSlots(): array
    {
        return array_map(static fn (WatchNotification $n) => $n->getType()->value . '@' . $n->getScheduledFor()->format('Y-m-d H:i'), $this->persisted);
    }

    public function testANewWatchGetsItsTwoDeadlines(): void
    {
        $stats = $this->scheduler()->reconcile($this->watch('2026-10-05 08:00:00'), $this->now);

        self::assertSame(['created' => 2, 'cancelled' => 0], $stats);
        self::assertSame(['J1@2026-10-04 08:00', 'H1@2026-10-05 07:00'], $this->persistedSlots());
    }

    public function testReconcilingAgainWithoutChangeCreatesNothing(): void
    {
        $watch = $this->watch('2026-10-05 08:00:00');
        $this->existing($watch, WatchNotificationType::J1, '2026-10-04 08:00:00');
        $this->existing($watch, WatchNotificationType::H1, '2026-10-05 07:00:00');

        $stats = $this->scheduler()->reconcile($watch, $this->now->modify('+1 hour'));

        self::assertSame(['created' => 0, 'cancelled' => 0], $stats);
        self::assertSame([], $this->persisted);
    }

    public function testAMovedOpeningCancelsPendingDeadlinesAndPlansNewOnes(): void
    {
        $watch = $this->watch('2026-10-05 11:00:00');
        $oldJ1 = $this->existing($watch, WatchNotificationType::J1, '2026-10-04 08:00:00');
        $oldH1 = $this->existing($watch, WatchNotificationType::H1, '2026-10-05 07:00:00');

        $stats = $this->scheduler()->reconcile($watch, $this->now);

        self::assertSame(['created' => 2, 'cancelled' => 2], $stats);
        self::assertSame(WatchNotificationStatus::Cancelled, $oldJ1->getStatus());
        self::assertSame(WatchNotificationStatus::Cancelled, $oldH1->getStatus());
        self::assertSame(['J1@2026-10-04 11:00', 'H1@2026-10-05 10:00'], $this->persistedSlots());
    }

    public function testASentJ1WithinAnHourOfTheNewTimeIsNotResent(): void
    {
        $watch = $this->watch('2026-10-05 08:30:00');
        $this->existing($watch, WatchNotificationType::J1, '2026-10-04 08:00:00', WatchNotificationStatus::Sent);
        $h1 = $this->existing($watch, WatchNotificationType::H1, '2026-10-05 07:00:00');

        $stats = $this->scheduler()->reconcile($watch, $this->now);

        self::assertSame(['created' => 1, 'cancelled' => 1], $stats, 'la H-1 est recalée, la J-1 partie reste acquise');
        self::assertSame(WatchNotificationStatus::Cancelled, $h1->getStatus());
        self::assertSame(['H1@2026-10-05 07:30'], $this->persistedSlots());
    }

    public function testASentJ1MovedByMoreThanAnHourIsPlannedAgain(): void
    {
        $watch = $this->watch('2026-10-08 08:00:00');
        $this->existing($watch, WatchNotificationType::J1, '2026-10-04 08:00:00', WatchNotificationStatus::Sent);

        $this->scheduler()->reconcile($watch, $this->now);

        self::assertContains('J1@2026-10-07 08:00', $this->persistedSlots());
    }

    public function testACancelledRowAtTheSameTimeIsRevivedRatherThanDuplicated(): void
    {
        $watch = $this->watch('2026-10-05 08:00:00');
        $cancelled = $this->existing($watch, WatchNotificationType::J1, '2026-10-04 08:00:00', WatchNotificationStatus::Cancelled);
        $this->existing($watch, WatchNotificationType::H1, '2026-10-05 07:00:00');

        $stats = $this->scheduler()->reconcile($watch, $this->now);

        self::assertSame(['created' => 1, 'cancelled' => 0], $stats);
        self::assertSame([], $this->persisted, 'rien de nouveau : la ligne annulée repart (contrainte d\'unicité)');
        self::assertTrue($cancelled->isPending());
    }

    public function testDisablingH1CancelsItAndReenablingBringsItBack(): void
    {
        $watch = $this->watch('2026-10-05 08:00:00')->setNotifyH1(false);
        $this->existing($watch, WatchNotificationType::J1, '2026-10-04 08:00:00');
        $h1 = $this->existing($watch, WatchNotificationType::H1, '2026-10-05 07:00:00');

        $this->scheduler()->reconcile($watch, $this->now);
        self::assertSame(WatchNotificationStatus::Cancelled, $h1->getStatus());

        $watch->setNotifyH1(true);
        $this->scheduler()->reconcile($watch, $this->now);
        self::assertTrue($h1->isPending());
        self::assertSame([], $this->persisted);
    }

    public function testAnImminentJ1AlreadyOverdueIsNotReplannedEveryHour(): void
    {
        $watch = $this->watch('2026-10-01 14:00:00');
        $this->existing($watch, WatchNotificationType::J1, '2026-10-01 07:30:00');
        $this->existing($watch, WatchNotificationType::H1, '2026-10-01 13:00:00');

        $stats = $this->scheduler()->reconcile($watch, $this->now);

        self::assertSame(['created' => 0, 'cancelled' => 0], $stats);
    }

    public function testAnInactiveWatchLosesItsPendingDeadlines(): void
    {
        $watch = $this->watch('2026-10-05 08:00:00')->setActive(false);
        $j1 = $this->existing($watch, WatchNotificationType::J1, '2026-10-04 08:00:00');

        $stats = $this->scheduler()->reconcile($watch, $this->now);

        self::assertSame(['created' => 0, 'cancelled' => 1], $stats);
        self::assertSame(WatchNotificationStatus::Cancelled, $j1->getStatus());
    }
}
