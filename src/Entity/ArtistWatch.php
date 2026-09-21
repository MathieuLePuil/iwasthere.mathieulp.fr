<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ArtistWatchRepository;
use App\Ticketmaster\NameNormalizer;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Un artiste de la wishlist : l'utilisateur veut savoir quand une date de cet
 * artiste apparaît dans le catalogue Ticketmaster France (voir ArtistAnnouncer).
 *
 * `artistNormalized` est la clé de rapprochement avec `tm_event.artists_normalized`
 * (« | muse | ») : nom entier, pas préfixe — « Muse » ne déclenche pas sur
 * « Muse by Soaked ». `artistName` garde la graphie saisie pour l'affichage.
 *
 * Les artistes déjà vus ne sont pas stockés ici : ils se relisent dans le
 * journal (`User::$alertSeenArtists` active ce second mode).
 */
#[ORM\Entity(repositoryClass: ArtistWatchRepository::class)]
#[ORM\Table(name: 'artist_watch')]
#[ORM\UniqueConstraint(name: 'uq_artist_watch_user_artist', columns: ['user_id', 'artist_normalized'])]
#[ORM\Index(name: 'idx_artist_watch_artist', columns: ['artist_normalized'])]
class ArtistWatch
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 255)]
    private string $artistName;

    #[ORM\Column(length: 255)]
    private string $artistNormalized;

    #[ORM\Column(type: 'datetime_utc_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(User $user, string $artistName)
    {
        $this->id = Uuid::v7();
        $this->user = $user;
        $this->artistName = trim($artistName);
        $this->artistNormalized = NameNormalizer::normalize($artistName);
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getArtistName(): string
    {
        return $this->artistName;
    }

    public function getArtistNormalized(): string
    {
        return $this->artistNormalized;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
