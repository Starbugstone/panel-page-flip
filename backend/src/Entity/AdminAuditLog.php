<?php

namespace App\Entity;

use App\Repository\AdminAuditLogRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: AdminAuditLogRepository::class)]
#[ORM\Table(name: 'admin_audit_log')]
#[ORM\Index(name: 'IDX_5BCE80B6642B8210', columns: ['admin_user_id'])]
#[ORM\Index(name: 'IDX_ADMIN_AUDIT_CREATED_AT', columns: ['created_at'])]
class AdminAuditLog
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $adminUser = null;

    #[ORM\Column(length: 100)]
    private string $action = '';

    #[ORM\Column(length: 100)]
    private string $targetType = '';

    #[ORM\Column(nullable: true)]
    private ?int $targetId = null;

    /** @var array<array-key, mixed>|null */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $payload = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getAdminUser(): ?User
    {
        return $this->adminUser;
    }

    public function setAdminUser(?User $adminUser): static
    {
        $this->adminUser = $adminUser;
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

    public function getTargetType(): string
    {
        return $this->targetType;
    }

    public function setTargetType(string $targetType): static
    {
        $this->targetType = $targetType;
        return $this;
    }

    public function getTargetId(): ?int
    {
        return $this->targetId;
    }

    public function setTargetId(?int $targetId): static
    {
        $this->targetId = $targetId;
        return $this;
    }

    /** @return array<array-key, mixed>|null */
    public function getPayload(): ?array
    {
        return $this->payload;
    }

    /** @param array<array-key, mixed>|null $payload */
    public function setPayload(?array $payload): static
    {
        $this->payload = $payload;
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
}
