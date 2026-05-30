<?php

declare(strict_types=1);

namespace App\Entity;

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

    #[ORM\Column(options: ['default' => false])]
    private bool $notificationsEnabled = false;

    #[ORM\Column(options: ['default' => false])]
    private bool $notifCompletionEnabled = false;

    #[ORM\Column(length: 5, nullable: true, options: ['default' => '08:00'])]
    private ?string $notifCompletionTime = '08:00';

    #[ORM\Column(options: ['default' => false])]
    private bool $notifPresenceEnabled = false;

    #[ORM\Column(options: ['default' => false])]
    private bool $notifFriendRequestEnabled = false;

    #[ORM\Column(length: 20, options: ['default' => 'user'])]
    private string $role = 'user';

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $passwordResetToken = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $passwordResetTokenExpiresAt = null;

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

    public function isNotificationsEnabled(): bool
    {
        return $this->notificationsEnabled;
    }

    public function setNotificationsEnabled(bool $notificationsEnabled): static
    {
        $this->notificationsEnabled = $notificationsEnabled;

        return $this;
    }

    public function isNotifCompletionEnabled(): bool
    {
        return $this->notifCompletionEnabled;
    }

    public function setNotifCompletionEnabled(bool $notifCompletionEnabled): static
    {
        $this->notifCompletionEnabled = $notifCompletionEnabled;

        return $this;
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

    public function isNotifPresenceEnabled(): bool
    {
        return $this->notifPresenceEnabled;
    }

    public function setNotifPresenceEnabled(bool $notifPresenceEnabled): static
    {
        $this->notifPresenceEnabled = $notifPresenceEnabled;

        return $this;
    }

    public function isNotifFriendRequestEnabled(): bool
    {
        return $this->notifFriendRequestEnabled;
    }

    public function setNotifFriendRequestEnabled(bool $notifFriendRequestEnabled): static
    {
        $this->notifFriendRequestEnabled = $notifFriendRequestEnabled;

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
