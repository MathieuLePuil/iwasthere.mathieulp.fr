<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ReactionRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Un emoji posé par quelqu'un sur la participation d'un ami. N'importe lequel :
 * le sélecteur ne fait que proposer des raccourcis.
 *
 * La contrainte porte sur le triplet (participation, auteur, emoji) et non sur
 * la paire : chacun peut cumuler 🔥 et 🎉 sur le même souvenir, mais pas poser
 * deux fois le même — c'est ce que la bascule côté client suppose. Son unicité
 * s'entend au sens de la collation de la colonne, qui tient '❤️' et '❤' pour un
 * seul et même emoji.
 */
#[ORM\Entity(repositoryClass: ReactionRepository::class)]
#[ORM\UniqueConstraint(name: 'uq_reaction', columns: ['participation_id', 'user_id', 'emoji'])]
class Reaction
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: EventParticipation::class)]
    #[ORM\JoinColumn(name: 'participation_id', referencedColumnName: 'id', nullable: false)]
    private EventParticipation $participation;

    /** L'auteur de la réaction, jamais celui de la participation. */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false)]
    private User $user;

    /**
     * L'emoji lui-même, et non un code : il n'y a pas de catalogue fermé à référencer.
     * Toujours passé par ReactionEmoji::normalize() en amont — rien ici ne le revalide.
     */
    #[ORM\Column(length: 20)]
    private string $emoji;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->id = Uuid::v7();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getParticipation(): EventParticipation
    {
        return $this->participation;
    }

    public function setParticipation(EventParticipation $participation): static
    {
        $this->participation = $participation;

        return $this;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function setUser(User $user): static
    {
        $this->user = $user;

        return $this;
    }

    public function getEmoji(): string
    {
        return $this->emoji;
    }

    public function setEmoji(string $emoji): static
    {
        $this->emoji = $emoji;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
