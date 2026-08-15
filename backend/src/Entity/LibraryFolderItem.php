<?php

namespace App\Entity;

use App\Repository\LibraryFolderItemRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** One viewer's optional placement of one visible comic. Absence means root. */
#[ORM\Entity(repositoryClass: LibraryFolderItemRepository::class)]
#[ORM\UniqueConstraint(name: 'UNIQ_library_folder_item_user_comic', columns: ['user_id', 'comic_id'])]
#[ORM\Index(name: 'IDX_library_folder_item_user_folder', columns: ['user_id', 'folder_id'])]
#[ORM\Index(name: 'IDX_library_folder_item_comic', columns: ['comic_id'])]
class LibraryFolderItem
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $user = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Comic $comic = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?LibraryFolder $folder = null;

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

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(User $user): static
    {
        $this->user = $user;

        return $this;
    }

    public function getComic(): ?Comic
    {
        return $this->comic;
    }

    public function setComic(Comic $comic): static
    {
        $this->comic = $comic;

        return $this;
    }

    public function getFolder(): ?LibraryFolder
    {
        return $this->folder;
    }

    public function setFolder(LibraryFolder $folder): static
    {
        $this->folder = $folder;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
