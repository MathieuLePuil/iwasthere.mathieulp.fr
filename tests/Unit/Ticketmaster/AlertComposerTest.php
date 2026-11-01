<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ticketmaster;

use App\Entity\WatchNotification;
use App\Notification\NotificationType;
use App\Tests\Support\Fixtures;
use App\Ticketmaster\AlertComposer;
use App\Ticketmaster\WatchNotificationType;
use PHPUnit\Framework\TestCase;

final class AlertComposerTest extends TestCase
{
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->now = new \DateTimeImmutable('2026-10-02 08:00:00', new \DateTimeZone('UTC'));
    }

    private function notification(string $name, string $onsaleUtc, WatchNotificationType $type, string $scheduledUtc, ?string $url = 'https://tm.test/e'): WatchNotification
    {
        $event = Fixtures::tmEvent('id-' . md5($name), $name, $onsaleUtc, $url, '2027-03-14 19:00:00');
        $watch = Fixtures::watch(Fixtures::user(), $event);

        return new WatchNotification($watch, $type->isOnsaleAlert() ? $event->getPublicSaleWindow() : null, $type, new \DateTimeImmutable($scheduledUtc, new \DateTimeZone('UTC')));
    }

    public function testJ1ForASingleEventLinksToTicketmasterAndSpeaksParisTime(): void
    {
        $alert = AlertComposer::compose([
            $this->notification('Indochine', '2026-10-03 08:00:00', WatchNotificationType::J1, '2026-10-02 08:00:00'),
        ], $this->now);

        self::assertSame('Billetterie demain : Indochine', $alert['title']);
        self::assertSame('Accor Arena, Paris · concert le dim. 14 mars 2027 à 20h00 · ouverture sam. 3 oct. à 10h00', $alert['body']);
        self::assertSame('https://tm.test/e', $alert['url']);
        self::assertSame(NotificationType::TicketOnsale, $alert['type']);
    }

    public function testAJ1SentLateIsAnImminentOpening(): void
    {
        $alert = AlertComposer::compose([
            $this->notification('PNL', '2026-10-02 14:00:00', WatchNotificationType::J1, '2026-10-02 08:00:00'),
        ], $this->now);

        self::assertSame('Ouverture imminente : PNL', $alert['title']);
        self::assertStringContainsString('billetterie ven. 2 oct. à 16h00 (dans 6 h)', $alert['body']);
    }

    public function testH1MentionsTheCountdownOnTicketmaster(): void
    {
        $alert = AlertComposer::compose([
            $this->notification('Indochine', '2026-10-02 09:00:00', WatchNotificationType::H1, '2026-10-02 08:00:00'),
        ], $this->now);

        self::assertSame('Billetterie dans 1 h : Indochine', $alert['title']);
        self::assertStringContainsString('ouverture à 11h00', $alert['body']);
        self::assertStringContainsString('compte à rebours', $alert['body']);
    }

    public function testSeveralOpeningsMakeOneNotificationListingThem(): void
    {
        $alert = AlertComposer::compose([
            $this->notification('Indochine', '2026-10-03 08:00:00', WatchNotificationType::J1, '2026-10-02 08:00:00'),
            $this->notification('Zaho de Sagazan', '2026-10-03 08:00:00', WatchNotificationType::J1, '2026-10-02 08:00:00'),
        ], $this->now);

        self::assertSame('2 billetteries ouvrent bientôt', $alert['title']);
        self::assertSame('Indochine (10h00), Zaho de Sagazan (10h00)', $alert['body']);
        self::assertSame(AlertComposer::WATCH_LIST_URL, $alert['url']);
        self::assertCount(2, $alert['items']);
        self::assertSame('https://tm.test/e', $alert['items'][1]['url']);
    }

    public function testChangesHaveTheirOwnWording(): void
    {
        $cancelled = AlertComposer::compose([$this->notification('U2', '2026-10-03 08:00:00', WatchNotificationType::Cancelled, '2026-10-02 08:00:00')], $this->now);
        $rescheduled = AlertComposer::compose([$this->notification('U2', '2026-10-03 08:00:00', WatchNotificationType::Rescheduled, '2026-10-02 08:00:00')], $this->now);
        $moved = AlertComposer::compose([$this->notification('U2', '2026-10-03 08:00:00', WatchNotificationType::DateChanged, '2026-10-02 08:00:00')], $this->now);

        self::assertSame('Annulé : U2', $cancelled['title']);
        self::assertStringContainsString('ta veille est désactivée', $cancelled['body']);
        self::assertSame(NotificationType::TicketEventChange, $cancelled['type']);
        self::assertSame('Reporté : U2', $rescheduled['title']);
        self::assertStringContainsString('ta veille est maintenue', $rescheduled['body']);
        self::assertSame('Ouverture déplacée : U2', $moved['title']);
        self::assertStringContainsString('nouvelle ouverture sam. 3 oct. à 10h00', $moved['body']);
    }
}
