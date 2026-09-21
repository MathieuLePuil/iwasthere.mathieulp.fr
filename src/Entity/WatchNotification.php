<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\WatchNotificationRepository;
use App\Ticketmaster\WatchNotificationStatus;
use App\Ticketmaster\WatchNotificationType;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Une échéance d'une veille : « envoyer l'alerte J-1 de cette fenêtre de vente
 * à cet instant ». Planifiée par la synchronisation, envoyée par le cron à la
 * minute (app:notifications:dispatch), qui la pousse dans Messenger.
 *
 * L'unicité sur (watch, sale_window, type, scheduled_for) est la garantie
 * qu'une reprogrammation ne produit jamais de doublon : replanifier à la même
 * heure retrouve la même ligne. Les changements d'état (annulation, report,
 * date modifiée) n'ont pas de fenêtre : `saleWindow` est alors nul.
 *
 * `pushBatch` regroupe les échéances parties dans un même push (plusieurs
 * ouvertures à 10:00) ; c'est aussi la clé de l'accusé de réception que renvoie
 * le service worker, faute duquel l'e-mail de secours part 5 minutes plus tard.
 */
#[ORM\Entity(repositoryClass: WatchNotificationRepository::class)]
#[ORM\Table(name: 'watch_notification')]
#[ORM\UniqueConstraint(name: 'uq_watch_notification_slot', columns: ['watch_id', 'sale_window_id', 'type', 'scheduled_for'])]
#[ORM\Index(name: 'idx_watch_notification_due', columns: ['status', 'scheduled_for'])]
#[ORM\Index(name: 'idx_watch_notification_batch', columns: ['push_batch'])]
#[ORM\Index(name: 'idx_watch_notification_sent', columns: ['sent_at'])]
class WatchNotification
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: EventWatch::class)]
    #[ORM\JoinColumn(name: 'watch_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private EventWatch $watch;

    #[ORM\ManyToOne(targetEntity: TmSaleWindow::class)]
    #[ORM\JoinColumn(name: 'sale_window_id', referencedColumnName: 'id', nullable: true, onDelete: 'CASCADE')]
    private ?TmSaleWindow $saleWindow;

    #[ORM\Column(length: 20, enumType: WatchNotificationType::class)]
    private WatchNotificationType $type;

    #[ORM\Column(type: 'datetime_utc_immutable')]
    private \DateTimeImmutable $scheduledFor;

    #[ORM\Column(type: 'datetime_utc_immutable', nullable: true)]
    private ?\DateTimeImmutable $sentAt = null;

    /** Le service worker a reçu le push (voir PushController::ack) */
    #[ORM\Column(type: 'datetime_utc_immutable', nullable: true)]
    private ?\DateTimeImmutable $acknowledgedAt = null;

    #[ORM\Column(length: 12, enumType: WatchNotificationStatus::class)]
    private WatchNotificationStatus $status = WatchNotificationStatus::Pending;

    #[ORM\Column(type: 'uuid', nullable: true)]
    private ?Uuid $pushBatch = null;

    #[ORM\Column(type: 'datetime_utc_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(EventWatch $watch, ?TmSaleWindow $saleWindow, WatchNotificationType $type, \DateTimeImmutable $scheduledFor)
    {
        $this->id = Uuid::v7();
        $this->watch = $watch;
        $this->saleWindow = $saleWindow;
        $this->type = $type;
        $this->scheduledFor = $scheduledFor;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getWatch(): EventWatch
    {
        return $this->watch;
    }

    public function getSaleWindow(): ?TmSaleWindow
    {
        return $this->saleWindow;
    }

    public function getType(): WatchNotificationType
    {
        return $this->type;
    }

    public function getScheduledFor(): \DateTimeImmutable
    {
        return $this->scheduledFor;
    }

    public function getSentAt(): ?\DateTimeImmutable
    {
        return $this->sentAt;
    }

    public function getAcknowledgedAt(): ?\DateTimeImmutable
    {
        return $this->acknowledgedAt;
    }

    public function acknowledge(\DateTimeImmutable $at): static
    {
        $this->acknowledgedAt ??= $at;

        return $this;
    }

    public function getStatus(): WatchNotificationStatus
    {
        return $this->status;
    }

    public function isPending(): bool
    {
        return $this->status === WatchNotificationStatus::Pending;
    }

    /** Reprogrammée : la ligne reste (unicité) mais ne partira pas. */
    public function cancel(): static
    {
        $this->status = WatchNotificationStatus::Cancelled;

        return $this;
    }

    /** Une ligne annulée que la replanification retrouve à la même heure repart. */
    public function revive(): static
    {
        $this->status = WatchNotificationStatus::Pending;
        $this->sentAt = null;
        $this->acknowledgedAt = null;
        $this->pushBatch = null;

        return $this;
    }

    /** Prise par le cron d'envoi : `sentAt` est posé ici, c'est l'heure de départ effective. */
    public function markQueued(Uuid $batch, \DateTimeImmutable $at): static
    {
        $this->status = WatchNotificationStatus::Queued;
        $this->pushBatch = $batch;
        $this->sentAt = $at;

        return $this;
    }

    public function markSent(): static
    {
        $this->status = WatchNotificationStatus::Sent;

        return $this;
    }

    /** Statuts terminaux hors envoi (journalisation seule, plafond, rien à envoyer, échec). */
    public function close(WatchNotificationStatus $status, \DateTimeImmutable $at): static
    {
        $this->status = $status;
        $this->sentAt ??= $at;

        return $this;
    }

    public function getPushBatch(): ?Uuid
    {
        return $this->pushBatch;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * Une J-1 envoyée à moins de 24 h de l'ouverture est une « ouverture
     * imminente » : l'événement est entré au catalogue trop tard pour la veille.
     */
    public function isImminent(): bool
    {
        if ($this->type !== WatchNotificationType::J1 || $this->saleWindow === null) {
            return false;
        }

        return $this->saleWindow->getStartsAtUtc()->getTimestamp() - $this->scheduledFor->getTimestamp() < 24 * 3600 - 60;
    }
}
