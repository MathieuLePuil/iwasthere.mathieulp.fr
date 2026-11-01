<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\TmEventRepository;
use App\Ticketmaster\NameNormalizer;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Un événement du catalogue Ticketmaster (flux Discovery Feed, France).
 *
 * La clé est l'`eventId` de Ticketmaster, stable d'un flux à l'autre. Les
 * lignes sont écrites par app:ticketmaster:sync (en SQL par lots, pas par
 * l'ORM — 61 000 événements par passage) et lues par la recherche et les
 * veilles. Toutes les dates sont en UTC ; l'affichage passe par Europe/Paris.
 *
 * `payloadHash` résume les champs projetés : une ligne dont le hash n'a pas
 * bougé ne voit que son `lastSeenAt` rafraîchi. `lastSeenAt` sert à la purge —
 * un événement absent du flux depuis 7 jours et suivi par personne disparaît.
 */
#[ORM\Entity(repositoryClass: TmEventRepository::class)]
#[ORM\Table(name: 'tm_event')]
#[ORM\Index(name: 'idx_tm_event_city', columns: ['venue_city'])]
#[ORM\Index(name: 'idx_tm_event_segment', columns: ['segment'])]
#[ORM\Index(name: 'idx_tm_event_start', columns: ['event_start_utc'])]
#[ORM\Index(name: 'idx_tm_event_last_seen', columns: ['last_seen_at'])]
#[ORM\Index(name: 'ft_tm_event_name', columns: ['name_normalized'], flags: ['fulltext'])]
class TmEvent
{
    /**
     * L'`eventId` de Ticketmaster, sensible à la casse : « Z7r9jZ1AdAe8x » et
     * « z7r9jz1adae8x » sont deux événements. Avec la collation par défaut
     * (`_ci`) 399 des 7 539 événements Music se confondaient — d'où le binaire,
     * ici et sur chaque colonne qui référence celle-ci.
     */
    #[ORM\Id]
    #[ORM\Column(length: 64, options: ['collation' => 'utf8mb4_bin'])]
    private string $eventId;

    #[ORM\Column(length: 255)]
    private string $name;

    /** Minuscules, sans accents ni ponctuation — la colonne qu'interroge la recherche. */
    #[ORM\Column(length: 255)]
    private string $nameNormalized;

    /** onsale / offsale / cancelled / rescheduled (tel quel depuis le flux) */
    #[ORM\Column(length: 20)]
    private string $status;

    #[ORM\Column(type: 'datetime_utc_immutable', nullable: true)]
    private ?\DateTimeImmutable $eventStartUtc = null;

    /** Date du concert telle qu'imprimée sur le billet (fuseau de la salle) ; renseignée même sans heure. */
    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $eventStartLocalDate = null;

    /** « 20:00:00 », ou null quand l'heure n'est pas encore annoncée */
    #[ORM\Column(length: 8, nullable: true)]
    private ?string $eventStartLocalTime = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $venueName = null;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $venueCity = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $venueTimezone = null;

    /** Music, Arts & Theatre, Sports… (`classificationSegment`) */
    #[ORM\Column(length: 60, nullable: true)]
    private ?string $segment = null;

    #[ORM\Column(length: 60, nullable: true)]
    private ?string $genre = null;

    /** `primaryEventUrl` — l'action directe de chaque alerte ; sans lui, pas d'envoi */
    #[ORM\Column(length: 500, nullable: true)]
    private ?string $url = null;

    #[ORM\Column(length: 40, nullable: true)]
    private ?string $source = null;

    /** Le premier artiste de `attractions[]` (« Muse »), quand le flux le donne — 68 % des événements Music */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $artistName = null;

    /**
     * Tous les artistes, normalisés, entre séparateurs : « | muse | » ou
     * « | indochine | jean louis aubert | ». La recherche par artiste fait un
     * LIKE '%| terme%' (préfixe d'un nom) ou '%| terme |%' (nom entier).
     */
    #[ORM\Column(length: 1000, nullable: true)]
    private ?string $artistsNormalized = null;

    #[ORM\Column(type: 'datetime_utc_immutable')]
    private \DateTimeImmutable $firstSeenAt;

    #[ORM\Column(type: 'datetime_utc_immutable')]
    private \DateTimeImmutable $lastSeenAt;

    #[ORM\Column(length: 32)]
    private string $payloadHash;

    /** @var Collection<int, TmSaleWindow> */
    #[ORM\OneToMany(targetEntity: TmSaleWindow::class, mappedBy: 'event')]
    private Collection $saleWindows;

    public function __construct(string $eventId)
    {
        $this->eventId = $eventId;
        $this->firstSeenAt = new \DateTimeImmutable();
        $this->lastSeenAt = $this->firstSeenAt;
        $this->payloadHash = '';
        $this->saleWindows = new ArrayCollection();
    }

    public function getEventId(): string
    {
        return $this->eventId;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getNameNormalized(): string
    {
        return $this->nameNormalized;
    }

    public function setNameNormalized(string $nameNormalized): static
    {
        $this->nameNormalized = $nameNormalized;

        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function isCancelled(): bool
    {
        return $this->status === 'cancelled';
    }

    public function getEventStartUtc(): ?\DateTimeImmutable
    {
        return $this->eventStartUtc;
    }

    public function setEventStartUtc(?\DateTimeImmutable $eventStartUtc): static
    {
        $this->eventStartUtc = $eventStartUtc;

        return $this;
    }

    public function getEventStartLocalDate(): ?\DateTimeImmutable
    {
        return $this->eventStartLocalDate;
    }

    public function setEventStartLocalDate(?\DateTimeImmutable $date): static
    {
        $this->eventStartLocalDate = $date;

        return $this;
    }

    public function getEventStartLocalTime(): ?string
    {
        return $this->eventStartLocalTime;
    }

    public function setEventStartLocalTime(?string $time): static
    {
        $this->eventStartLocalTime = $time;

        return $this;
    }

    /**
     * Le concert, dans le fuseau de la salle : ce qu'on affiche. On part de
     * l'instant UTC quand il existe (fuseau de la salle, Paris par défaut),
     * sinon de la date locale seule.
     */
    public function getEventStartLocal(): ?\DateTimeImmutable
    {
        $tz = new \DateTimeZone($this->venueTimezone ?: 'Europe/Paris');
        if ($this->eventStartUtc !== null) {
            return $this->eventStartUtc->setTimezone($tz);
        }
        if ($this->eventStartLocalDate === null) {
            return null;
        }
        $local = new \DateTimeImmutable($this->eventStartLocalDate->format('Y-m-d'), $tz);
        if ($this->eventStartLocalTime !== null) {
            [$h, $m] = array_map('intval', explode(':', $this->eventStartLocalTime));
            $local = $local->setTime($h, $m);
        }

        return $local;
    }

    /** L'heure du concert est-elle connue ? (sinon on n'affiche que la date) */
    public function hasEventStartTime(): bool
    {
        return $this->eventStartUtc !== null || $this->eventStartLocalTime !== null;
    }

    public function getVenueName(): ?string
    {
        return $this->venueName;
    }

    public function setVenueName(?string $venueName): static
    {
        $this->venueName = $venueName;

        return $this;
    }

    public function getVenueCity(): ?string
    {
        return $this->venueCity;
    }

    public function setVenueCity(?string $venueCity): static
    {
        $this->venueCity = $venueCity;

        return $this;
    }

    public function getVenueTimezone(): ?string
    {
        return $this->venueTimezone;
    }

    public function setVenueTimezone(?string $venueTimezone): static
    {
        $this->venueTimezone = $venueTimezone;

        return $this;
    }

    /** « Olympia, Paris » — ou l'un des deux, ou rien */
    public function getPlace(): ?string
    {
        $parts = array_filter([$this->venueName, $this->venueCity]);

        return $parts === [] ? null : implode(', ', $parts);
    }

    public function getSegment(): ?string
    {
        return $this->segment;
    }

    public function setSegment(?string $segment): static
    {
        $this->segment = $segment;

        return $this;
    }

    public function getGenre(): ?string
    {
        return $this->genre;
    }

    public function setGenre(?string $genre): static
    {
        $this->genre = $genre;

        return $this;
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

    public function getSource(): ?string
    {
        return $this->source;
    }

    public function setSource(?string $source): static
    {
        $this->source = $source;

        return $this;
    }

    public function getArtistName(): ?string
    {
        return $this->artistName;
    }

    public function setArtistName(?string $artistName): static
    {
        $this->artistName = $artistName;

        return $this;
    }

    public function getArtistsNormalized(): ?string
    {
        return $this->artistsNormalized;
    }

    public function setArtistsNormalized(?string $artistsNormalized): static
    {
        $this->artistsNormalized = $artistsNormalized;

        return $this;
    }

    /** L'artiste s'il apporte quelque chose de plus que le nom de l'événement (« Muse » sous « PRESTATION HOSPITALITE MUSE ») */
    public function getArtistLabel(): ?string
    {
        if ($this->artistName === null || NameNormalizer::normalize($this->artistName) === $this->nameNormalized) {
            return null;
        }

        return $this->artistName;
    }

    public function getFirstSeenAt(): \DateTimeImmutable
    {
        return $this->firstSeenAt;
    }

    public function getLastSeenAt(): \DateTimeImmutable
    {
        return $this->lastSeenAt;
    }

    public function setLastSeenAt(\DateTimeImmutable $lastSeenAt): static
    {
        $this->lastSeenAt = $lastSeenAt;

        return $this;
    }

    public function getPayloadHash(): string
    {
        return $this->payloadHash;
    }

    public function setPayloadHash(string $payloadHash): static
    {
        $this->payloadHash = $payloadHash;

        return $this;
    }

    /** @return Collection<int, TmSaleWindow> */
    public function getSaleWindows(): Collection
    {
        return $this->saleWindows;
    }

    /** La mise en vente générale — la seule fenêtre alimentée sur le marché français aujourd'hui. */
    public function getPublicSaleWindow(): ?TmSaleWindow
    {
        foreach ($this->saleWindows as $window) {
            if ($window->isPublic()) {
                return $window;
            }
        }

        return null;
    }
}
