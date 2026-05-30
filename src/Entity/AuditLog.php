<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\AuditLogRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: AuditLogRepository::class)]
class AuditLog
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    private Uuid $id;

    #[ORM\Column(type: 'uuid')]
    private Uuid $superAdminUserId;

    #[ORM\Column(length: 50)]
    private string $action;

    #[ORM\Column(length: 100)]
    private string $entityType;

    #[ORM\Column(length: 255)]
    private string $entityId;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $fieldChanged = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $oldValue = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $newValue = null;

    #[ORM\Column]
    private \DateTimeImmutable $performedAt;

    public function __construct()
    {
        $this->id = Uuid::v7();
        $this->performedAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getSuperAdminUserId(): Uuid
    {
        return $this->superAdminUserId;
    }

    public function setSuperAdminUserId(Uuid $superAdminUserId): static
    {
        $this->superAdminUserId = $superAdminUserId;

        return $this;
    }

    public function getAction(): string
    {
        return $this->action;
    }

    public function setAction(string $action): static
    {
        $this->action = $action;

        return $this;
    }

    public function getEntityType(): string
    {
        return $this->entityType;
    }

    public function setEntityType(string $entityType): static
    {
        $this->entityType = $entityType;

        return $this;
    }

    public function getEntityId(): string
    {
        return $this->entityId;
    }

    public function setEntityId(string $entityId): static
    {
        $this->entityId = $entityId;

        return $this;
    }

    public function getFieldChanged(): ?string
    {
        return $this->fieldChanged;
    }

    public function setFieldChanged(?string $fieldChanged): static
    {
        $this->fieldChanged = $fieldChanged;

        return $this;
    }

    public function getOldValue(): ?string
    {
        return $this->oldValue;
    }

    public function setOldValue(?string $oldValue): static
    {
        $this->oldValue = $oldValue;

        return $this;
    }

    public function getNewValue(): ?string
    {
        return $this->newValue;
    }

    public function setNewValue(?string $newValue): static
    {
        $this->newValue = $newValue;

        return $this;
    }

    public function getPerformedAt(): \DateTimeImmutable
    {
        return $this->performedAt;
    }

    public function setPerformedAt(\DateTimeImmutable $performedAt): static
    {
        $this->performedAt = $performedAt;

        return $this;
    }
}
