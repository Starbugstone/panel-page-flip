<?php

namespace App\Entity;

use App\Repository\LibraryFolderRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A private, virtual location in one user's library.
 *
 * This is deliberately unrelated to Comic::filePath and Comic::dropboxPath.
 * Reorganising this tree must never touch an archive on disk.
 */
#[ORM\Entity(repositoryClass: LibraryFolderRepository::class)]
#[ORM\Index(name: 'IDX_library_folder_owner_parent', columns: ['owner_id', 'parent_id'])]
#[ORM\UniqueConstraint(name: 'UNIQ_library_folder_sibling_name', columns: ['owner_id', 'parent_scope', 'name'])]
#[ORM\HasLifecycleCallbacks]
class LibraryFolder
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $owner = null;

    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?self $parent = null;

    #[ORM\Column(length: 100)]
    private string $name = '';

    /**
     * MySQL unique indexes treat NULL parents as distinct, so the validated
     * parent id is mirrored as a non-null scope (zero for root). This gives
     * root and nested siblings the same race-proof uniqueness while retaining
     * the cascading self-reference; MySQL forbids that cascade when parent_id
     * participates in a generated-column expression.
     */
    #[ORM\Column(options: ['default' => 0])]
    private int $parentScope = 0;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    #[ORM\PreUpdate]
    public function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOwner(): ?User
    {
        return $this->owner;
    }

    public function setOwner(User $owner): static
    {
        $this->owner = $owner;

        return $this;
    }

    public function getParent(): ?self
    {
        return $this->parent;
    }

    public function setParent(?self $parent): static
    {
        $this->parent = $parent;
        $this->parentScope = $parent?->getId() ?? 0;

        return $this;
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

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
