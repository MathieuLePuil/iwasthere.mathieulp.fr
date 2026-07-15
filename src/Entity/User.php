<?php

declare(strict_types=1);

namespace App\Entity;

use App\Notification\NotificationType;
use App\Repository\UserRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: '`user`')]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    private Uuid $id;

    #[ORM\Column(length: 50, unique: true)]
    private string $username;

    #[ORM\Column(length: 100)]
    private string $displayName;

    #[ORM\Column(length: 180, unique: true)]
    private string $email;

    #[ORM\Column(nullable: true)]
    private ?string $password = null;

    #[ORM\Column(length: 255, nullable: true, unique: true)]
    private ?string $googleId = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $avatarUrl = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $bio = null;

    #[ORM\Column(length: 20, options: ['default' => 'friends'])]
    private string $profileVisibility = 'friends';

    #[ORM\Column(length: 20, options: ['default' => 'friends'])]
    private string $defaultEventVisibility = 'friends';

    /**
     * Push autorisés, par type de notification. Une clé absente vaut « activé » :
     * un type ajouté plus tard est reçu sans avoir à retoucher les lignes existantes.
     *
     * @var array<string, bool>
     */
    #[ORM\Column(type: 'json')]
    private array $notifPrefs = [];

    /** Heure d'envoi des rappels programmés (jour J, complétion), en heure française */
    #[ORM\Column(length: 5, nullable: true, options: ['default' => '08:00'])]
    private ?string $notifCompletionTime = '08:00';

    #[ORM\Column(length: 20, options: ['default' => 'user'])]
    private string $role = 'user';

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $passwordResetToken = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $passwordResetTokenExpiresAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $feedLastSeenAt = null;

    /** Année du Rewind débloqué, null si aucun */
    #[ORM\Column(nullable: true)]
    private ?int $rewindYear = null;

    /** Date de déblocage : le Rewind reste visible un mois à partir de là */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $rewindUnlockedAt = null;

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

    public function getUsername(): string
    {
        return $this->username;
    }

    public function setUsername(string $username): static
    {
        $this->username = $username;

        return $this;
    }

    public function getDisplayName(): string
    {
        return $this->displayName;
    }

    public function setDisplayName(string $displayName): static
    {
        $this->displayName = $displayName;

        return $this;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function setEmail(string $email): static
    {
        $this->email = $email;

        return $this;
    }

    public function getPassword(): ?string
    {
        return $this->password;
    }

    public function setPassword(?string $password): static
    {
        $this->password = $password;

        return $this;
    }

    public function getGoogleId(): ?string
    {
        return $this->googleId;
    }

    public function setGoogleId(?string $googleId): static
    {
        $this->googleId = $googleId;

        return $this;
    }

    public function getAvatarUrl(): ?string
    {
        return $this->avatarUrl;
    }

    public function setAvatarUrl(?string $avatarUrl): static
    {
        $this->avatarUrl = $avatarUrl;

        return $this;
    }

    public function getBio(): ?string
    {
        return $this->bio;
    }

    public function setBio(?string $bio): static
    {
        $this->bio = $bio;

        return $this;
    }

    public function getProfileVisibility(): string
    {
        return $this->profileVisibility;
    }

    public function setProfileVisibility(string $profileVisibility): static
    {
        $this->profileVisibility = $profileVisibility;

        return $this;
    }

    public function getDefaultEventVisibility(): string
    {
        return $this->defaultEventVisibility;
    }

    public function setDefaultEventVisibility(string $defaultEventVisibility): static
    {
        $this->defaultEventVisibility = $defaultEventVisibility;

        return $this;
    }

    /** @return array<string, bool> */
    public function getNotifPrefs(): array
    {
        return $this->notifPrefs;
    }

    /** @param array<string, bool> $notifPrefs */
    public function setNotifPrefs(array $notifPrefs): static
    {
        $this->notifPrefs = $notifPrefs;

        return $this;
    }

    /**
     * L'utilisateur veut-il un push pour ce type ? Activé tant qu'il n'a pas été
     * décoché. Couper tout se fait en se désabonnant depuis les Paramètres, ce
     * qui retire l'abonnement navigateur — il n'y a pas d'interrupteur en base.
     */
    public function wantsPush(NotificationType $type): bool
    {
        return (bool) ($this->notifPrefs[$type->value] ?? true);
    }

    public function getNotifCompletionTime(): ?string
    {
        return $this->notifCompletionTime;
    }

    public function setNotifCompletionTime(?string $notifCompletionTime): static
    {
        $this->notifCompletionTime = $notifCompletionTime;

        return $this;
    }

    public function getRole(): string
    {
        return $this->role;
    }

    public function setRole(string $role): static
    {
        $this->role = $role;

        return $this;
    }

    public function getPasswordResetToken(): ?string
    {
        return $this->passwordResetToken;
    }

    public function setPasswordResetToken(?string $passwordResetToken): static
    {
        $this->passwordResetToken = $passwordResetToken;
        return $this;
    }

    public function getPasswordResetTokenExpiresAt(): ?\DateTimeImmutable
    {
        return $this->passwordResetTokenExpiresAt;
    }

    public function setPasswordResetTokenExpiresAt(?\DateTimeImmutable $passwordResetTokenExpiresAt): static
    {
        $this->passwordResetTokenExpiresAt = $passwordResetTokenExpiresAt;
        return $this;
    }

    public function isPasswordResetTokenValid(): bool
    {
        return $this->passwordResetToken !== null
            && $this->passwordResetTokenExpiresAt !== null
            && $this->passwordResetTokenExpiresAt > new \DateTimeImmutable();
    }

    public function getFeedLastSeenAt(): ?\DateTimeImmutable
    {
        return $this->feedLastSeenAt;
    }

    public function setFeedLastSeenAt(?\DateTimeImmutable $feedLastSeenAt): static
    {
        $this->feedLastSeenAt = $feedLastSeenAt;
        return $this;
    }

    public function getRewindYear(): ?int
    {
        return $this->rewindYear;
    }

    public function getRewindUnlockedAt(): ?\DateTimeImmutable
    {
        return $this->rewindUnlockedAt;
    }

    /** Débloque le Rewind d'une année ; la fenêtre d'un mois repart de maintenant. */
    public function unlockRewind(int $year, ?\DateTimeImmutable $at = null): static
    {
        $this->rewindYear = $year;
        $this->rewindUnlockedAt = $at ?? new \DateTimeImmutable();

        return $this;
    }

    public function lockRewind(): static
    {
        $this->rewindYear = null;
        $this->rewindUnlockedAt = null;

        return $this;
    }

    /** Fin de la fenêtre de visibilité, un mois après le déblocage. */
    public function getRewindExpiresAt(): ?\DateTimeImmutable
    {
        return $this->rewindUnlockedAt?->modify('+1 month');
    }

    /**
     * Le Rewind est-il visible ? Il s'efface de lui-même au bout d'un mois :
     * rien ne le verrouille en base, c'est la date de déblocage qui fait foi.
     */
    public function isRewindAvailable(): bool
    {
        $expires = $this->getRewindExpiresAt();

        return $this->rewindYear !== null && $expires !== null && $expires > new \DateTimeImmutable();
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

    // UserInterface methods

    public function getRoles(): array
    {
        if ($this->role === 'superAdmin') {
            return ['ROLE_SUPER_ADMIN', 'ROLE_USER'];
        }

        return ['ROLE_USER'];
    }

    public function getUserIdentifier(): string
    {
        return $this->email;
    }

    public function eraseCredentials(): void
    {
        // Nothing to erase
    }
}
