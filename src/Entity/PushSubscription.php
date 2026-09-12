<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\PushSubscriptionRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Un abonnement Web Push : un navigateur (endpoint) rattaché à un compte.
 *
 * Longtemps tenu dans var/subscriptions.json, lu-modifié-réécrit sans verrou par
 * les requêtes web et par le worker — deux écritures simultanées perdaient des
 * abonnements. La table a une clé étrangère en cascade : supprimer le compte
 * emporte ses abonnements.
 */
#[ORM\Entity(repositoryClass: PushSubscriptionRepository::class)]
#[ORM\UniqueConstraint(name: 'uq_push_subscription_endpoint', columns: ['endpoint'])]
class PushSubscription
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 500)]
    private string $endpoint;

    #[ORM\Column(length: 255)]
    private string $p256dh;

    #[ORM\Column(length: 255)]
    private string $auth;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(User $user, string $endpoint, string $p256dh, string $auth)
    {
        $this->id = Uuid::v7();
        $this->user = $user;
        $this->endpoint = $endpoint;
        $this->p256dh = $p256dh;
        $this->auth = $auth;
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

    /** Un même navigateur qui se reconnecte sous un autre compte change de propriétaire. */
    public function setUser(User $user): static
    {
        $this->user = $user;

        return $this;
    }

    public function getEndpoint(): string
    {
        return $this->endpoint;
    }

    public function getP256dh(): string
    {
        return $this->p256dh;
    }

    public function getAuth(): string
    {
        return $this->auth;
    }

    public function setKeys(string $p256dh, string $auth): static
    {
        $this->p256dh = $p256dh;
        $this->auth = $auth;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /** La forme attendue par minishlink/web-push. */
    public function toArray(): array
    {
        return [
            'endpoint' => $this->endpoint,
            'keys' => ['p256dh' => $this->p256dh, 'auth' => $this->auth],
        ];
    }
}
