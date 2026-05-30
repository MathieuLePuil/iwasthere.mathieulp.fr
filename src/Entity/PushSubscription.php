<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\PushSubscriptionRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: PushSubscriptionRepository::class)]
class PushSubscription
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(type: 'text')]
    private string $endpoint;

    #[ORM\Column(length: 512)]
    private string $p256dh;

    #[ORM\Column(length: 255)]
    private string $auth;

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

    public function getUser(): User
    {
        return $this->user;
    }

    public function setUser(User $user): static
    {
        $this->user = $user;
        return $this;
    }

    public function getEndpoint(): string
    {
        return $this->endpoint;
    }

    public function setEndpoint(string $endpoint): static
    {
        $this->endpoint = $endpoint;
        return $this;
    }

    public function getP256dh(): string
    {
        return $this->p256dh;
    }

    public function setP256dh(string $p256dh): static
    {
        $this->p256dh = $p256dh;
        return $this;
    }

    public function getAuth(): string
    {
        return $this->auth;
    }

    public function setAuth(string $auth): static
    {
        $this->auth = $auth;
        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
