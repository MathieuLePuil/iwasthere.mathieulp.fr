<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\TmSaleWindowRepository;
use App\Ticketmaster\SaleWindowType;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Une fenêtre de vente d'un événement Ticketmaster : la mise en vente
 * générale (`onsaleStartDateTime` → `onsaleEndDateTime`) ou, si le flux en
 * expose un jour sur la France, une prévente (`presales[]`).
 *
 * Les alertes se planifient sur les fenêtres, pas sur l'événement : c'est la
 * raison d'être de cette table plutôt que d'une colonne `onsale_start`.
 *
 * Identité naturelle : (event, type, label) — le label est vide pour la vente
 * générale, c'est le nom de la prévente sinon. L'ingestion fait ses upserts
 * dessus.
 */
#[ORM\Entity(repositoryClass: TmSaleWindowRepository::class)]
#[ORM\Table(name: 'tm_sale_window')]
#[ORM\UniqueConstraint(name: 'uq_tm_sale_window_identity', columns: ['event_id', 'type', 'label'])]
#[ORM\Index(name: 'idx_tm_sale_window_starts', columns: ['starts_at_utc'])]
class TmSaleWindow
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: TmEvent::class, inversedBy: 'saleWindows')]
    #[ORM\JoinColumn(name: 'event_id', referencedColumnName: 'event_id', nullable: false, onDelete: 'CASCADE', options: ['collation' => 'utf8mb4_bin'])]
    private TmEvent $event;

    #[ORM\Column(length: 10, enumType: SaleWindowType::class)]
    private SaleWindowType $type;

    #[ORM\Column(length: 120, options: ['default' => ''])]
    private string $label = '';

    #[ORM\Column(type: 'datetime_utc_immutable')]
    private \DateTimeImmutable $startsAtUtc;

    #[ORM\Column(type: 'datetime_utc_immutable', nullable: true)]
    private ?\DateTimeImmutable $endsAtUtc = null;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $url = null;

    public function __construct(TmEvent $event, SaleWindowType $type, \DateTimeImmutable $startsAtUtc, string $label = '')
    {
        $this->id = Uuid::v7();
        $this->event = $event;
        $this->type = $type;
        $this->label = $label;
        $this->startsAtUtc = $startsAtUtc;
        $event->getSaleWindows()->add($this);
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getEvent(): TmEvent
    {
        return $this->event;
    }

    public function getType(): SaleWindowType
    {
        return $this->type;
    }

    public function isPublic(): bool
    {
        return $this->type === SaleWindowType::Public;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function getStartsAtUtc(): \DateTimeImmutable
    {
        return $this->startsAtUtc;
    }

    public function setStartsAtUtc(\DateTimeImmutable $startsAtUtc): static
    {
        $this->startsAtUtc = $startsAtUtc;

        return $this;
    }

    public function getEndsAtUtc(): ?\DateTimeImmutable
    {
        return $this->endsAtUtc;
    }

    public function setEndsAtUtc(?\DateTimeImmutable $endsAtUtc): static
    {
        $this->endsAtUtc = $endsAtUtc;

        return $this;
    }

    /** L'ouverture, en heure de Paris — jamais un décalage fixe, l'heure d'été change le 25 octobre 2026. */
    public function getStartsAtParis(): \DateTimeImmutable
    {
        return $this->startsAtUtc->setTimezone(new \DateTimeZone('Europe/Paris'));
    }

    public function isOpen(\DateTimeImmutable $now): bool
    {
        return $this->startsAtUtc <= $now && ($this->endsAtUtc === null || $this->endsAtUtc > $now);
    }

    public function getUrl(): ?string
    {
        return $this->url;
    }

    public function setUrl(?string $url): static
    {
        $this->url = $url;

        return $this;
    }
}
