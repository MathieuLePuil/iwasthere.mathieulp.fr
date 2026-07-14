<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\EventRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: EventRepository::class)]
class Event
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    private Uuid $id;

    #[ORM\Column(type: 'uuid', nullable: true)]
    private ?Uuid $createdByUserId = null;

    #[ORM\Column(length: 20)]
    private string $category;

    #[ORM\Column(length: 20)]
    private string $type;

    #[ORM\Column]
    private \DateTimeImmutable $date;

    #[ORM\Column(type: 'time_immutable', nullable: true)]
    private ?\DateTimeImmutable $startTime = null;

    #[ORM\Column(type: 'uuid', nullable: true)]
    private ?Uuid $venueId = null;

    #[ORM\ManyToOne(targetEntity: Venue::class)]
    #[ORM\JoinColumn(name: 'venue_id', referencedColumnName: 'id', nullable: true)]
    private ?Venue $venue = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $artistName = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $tournamentName = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $teams = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $tourName = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $finalScore = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $intermediateScores = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $winner = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $setlist = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $setlistEncores = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $setlistSource = null;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $setlistUrl = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $setlistImportedAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $setlistLastAttemptAt = null;

    #[ORM\Column(options: ['default' => 0])]
    private int $setlistRetryCount = 0;

    #[ORM\Column(options: ['default' => 0])]
    private int $participantCount = 0;

    #[ORM\Column(type: 'json')]
    private array $editHistory = [];

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime')]
    private \DateTime $updatedAt;

    public function __construct()
    {
        $this->id = Uuid::v7();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTime();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getCreatedByUserId(): ?Uuid
    {
        return $this->createdByUserId;
    }

    public function setCreatedByUserId(?Uuid $createdByUserId): static
    {
        $this->createdByUserId = $createdByUserId;

        return $this;
    }

    public function getCategory(): string
    {
        return $this->category;
    }

    public function setCategory(string $category): static
    {
        $this->category = $category;

        return $this;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function setType(string $type): static
    {
        $this->type = $type;

        return $this;
    }

    public function getDate(): \DateTimeImmutable
    {
        return $this->date;
    }

    public function setDate(\DateTimeImmutable $date): static
    {
        $this->date = $date;

        return $this;
    }

    /** Même convention que le statut de participation : date >= aujourd'hui ⇒ à venir */
    public function isPast(): bool
    {
        return $this->date < new \DateTimeImmutable('today');
    }

    public function getStartTime(): ?\DateTimeImmutable
    {
        return $this->startTime;
    }

    public function setStartTime(?\DateTimeImmutable $startTime): static
    {
        $this->startTime = $startTime;

        return $this;
    }

    /** Date + heure de début ; défaut 21h (musique) ou 16h (sport) si non renseignée */
    public function getStartDateTime(): \DateTimeImmutable
    {
        $time = $this->startTime?->format('H:i')
            ?? ($this->category === 'sport' ? '16:00' : '21:00');

        return new \DateTimeImmutable($this->date->format('Y-m-d') . ' ' . $time);
    }

    public function getVenueId(): ?Uuid
    {
        return $this->venueId;
    }

    public function setVenueId(?Uuid $venueId): static
    {
        $this->venueId = $venueId;

        return $this;
    }

    public function getVenue(): ?Venue
    {
        return $this->venue;
    }

    public function setVenue(?Venue $venue): static
    {
        $this->venue = $venue;
        $this->venueId = $venue?->getId();

        return $this;
    }

    public function getArtistName(): ?string
    {
        return $this->artistName;
    }

    public function setArtistName(?string $artistName): static
    {
        $artistName = $artistName !== null ? preg_replace('/^\s+|\s+$/u', '', $artistName) : null;
        $this->artistName = $artistName !== '' ? $artistName : null;

        return $this;
    }

    public function getTournamentName(): ?string
    {
        return $this->tournamentName;
    }

    public function setTournamentName(?string $tournamentName): static
    {
        $this->tournamentName = $tournamentName;

        return $this;
    }

    public function getTeams(): ?string
    {
        return $this->teams;
    }

    public function setTeams(?string $teams): static
    {
        $this->teams = $teams;

        return $this;
    }

    public function getTourName(): ?string
    {
        return $this->tourName;
    }

    public function setTourName(?string $tourName): static
    {
        $this->tourName = $tourName;

        return $this;
    }

    public function getFinalScore(): ?string
    {
        return $this->finalScore;
    }

    public function setFinalScore(?string $finalScore): static
    {
        $this->finalScore = $finalScore;

        return $this;
    }

    public function getIntermediateScores(): ?array
    {
        return $this->intermediateScores;
    }

    public function setIntermediateScores(?array $intermediateScores): static
    {
        $this->intermediateScores = $intermediateScores;

        return $this;
    }

    public function getWinner(): ?string
    {
        return $this->winner;
    }

    public function setWinner(?string $winner): static
    {
        $this->winner = $winner;

        return $this;
    }

    /**
     * Score line ready for display: both sides, the score and the winning
     * side (1, 2 or null on draw/unknown). Tennis scores are set-based
     * ("6-4, 7-5"), the other sports use a plain "2 - 0" total.
     * Returns null when there is no score or the teams can't be split.
     */
    public function getScoreline(): ?array
    {
        if (!$this->finalScore || !$this->teams || !str_contains($this->teams, ' vs ')) {
            return null;
        }
        [$team1, $team2] = array_map('trim', explode(' vs ', $this->teams, 2));

        // Explicit winner ('1'/'2' from the checkbox; legacy rows may hold a name)
        if ($this->winner !== null && $this->winner !== '') {
            $explicit = match (trim($this->winner)) {
                '1' => 1,
                '2' => 2,
                default => strcasecmp(trim($this->winner), $team1) === 0 ? 1
                    : (strcasecmp(trim($this->winner), $team2) === 0 ? 2 : null),
            };
            if ($explicit !== null) {
                return [
                    'team1' => $team1,
                    'team2' => $team2,
                    'score' => trim($this->finalScore),
                    'winner' => $explicit,
                ];
            }
        }

        $cmp = 0;
        if ($this->type === 'tennis') {
            $won1 = $won2 = 0;
            foreach (explode(',', $this->finalScore) as $set) {
                if (preg_match('/(\d+)\s*-\s*(\d+)/', $set, $m)) {
                    $won1 += (int) $m[1] > (int) $m[2] ? 1 : 0;
                    $won2 += (int) $m[2] > (int) $m[1] ? 1 : 0;
                }
            }
            $cmp = $won1 <=> $won2;
        } elseif (preg_match('/^\s*(\d+)\s*-\s*(\d+)\s*$/', $this->finalScore, $m)) {
            $cmp = (int) $m[1] <=> (int) $m[2];
        }

        return [
            'team1' => $team1,
            'team2' => $team2,
            'score' => trim($this->finalScore),
            'winner' => $cmp === 0 ? null : ($cmp > 0 ? 1 : 2),
        ];
    }

    public function getSetlist(): ?array
    {
        return $this->setlist;
    }

    public function setSetlist(?array $setlist): static
    {
        $this->setlist = $setlist;

        return $this;
    }

    public function getSetlistEncores(): ?array
    {
        return $this->setlistEncores;
    }

    public function setSetlistEncores(?array $setlistEncores): static
    {
        $this->setlistEncores = $setlistEncores;

        return $this;
    }

    /** Normalize legacy string[] to object[] for display */
    public function getSetlistNormalized(): array
    {
        return array_map(
            fn($s) => is_string($s) ? ['name' => $s, 'tape' => false, 'info' => null, 'with' => null] : $s,
            $this->setlist ?? []
        );
    }

    public function getSetlistEncoresNormalized(): array
    {
        return array_map(
            fn($s) => is_string($s) ? ['name' => $s, 'tape' => false, 'info' => null, 'with' => null] : $s,
            $this->setlistEncores ?? []
        );
    }

    public function getSetlistSource(): ?string
    {
        return $this->setlistSource;
    }

    public function setSetlistSource(?string $setlistSource): static
    {
        $this->setlistSource = $setlistSource;

        return $this;
    }

    public function getSetlistUrl(): ?string
    {
        return $this->setlistUrl;
    }

    public function setSetlistUrl(?string $setlistUrl): static
    {
        $this->setlistUrl = $setlistUrl;

        return $this;
    }

    public function getSetlistImportedAt(): ?\DateTimeImmutable
    {
        return $this->setlistImportedAt;
    }

    public function setSetlistImportedAt(?\DateTimeImmutable $setlistImportedAt): static
    {
        $this->setlistImportedAt = $setlistImportedAt;

        return $this;
    }

    public function getSetlistLastAttemptAt(): ?\DateTimeImmutable
    {
        return $this->setlistLastAttemptAt;
    }

    public function setSetlistLastAttemptAt(?\DateTimeImmutable $setlistLastAttemptAt): static
    {
        $this->setlistLastAttemptAt = $setlistLastAttemptAt;

        return $this;
    }

    public function getSetlistRetryCount(): int
    {
        return $this->setlistRetryCount;
    }

    public function setSetlistRetryCount(int $setlistRetryCount): static
    {
        $this->setlistRetryCount = $setlistRetryCount;

        return $this;
    }

    public function getParticipantCount(): int
    {
        return $this->participantCount;
    }

    public function setParticipantCount(int $participantCount): static
    {
        $this->participantCount = $participantCount;

        return $this;
    }

    public function getEditHistory(): array
    {
        return $this->editHistory;
    }

    public function setEditHistory(array $editHistory): static
    {
        $this->editHistory = $editHistory;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): static
    {
        $this->createdAt = $createdAt;

        return $this;
    }

    public function getUpdatedAt(): \DateTime
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(\DateTime $updatedAt): static
    {
        $this->updatedAt = $updatedAt;

        return $this;
    }
}
