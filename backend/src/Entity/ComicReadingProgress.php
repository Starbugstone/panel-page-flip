<?php

namespace App\Entity;

use App\Repository\ComicReadingProgressRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ComicReadingProgressRepository::class)]
#[ORM\HasLifecycleCallbacks]
class ComicReadingProgress
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'readingProgress')]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $user = null;

    #[ORM\ManyToOne(inversedBy: 'readingProgresses')]
    #[ORM\JoinColumn(nullable: false, onDelete: "CASCADE")]
    private ?Comic $comic = null;

    #[ORM\Column]
    private ?int $currentPage = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $lastReadAt = null;

    #[ORM\Column]
    private ?bool $completed = null;

    /**
     * Counter supplied by the reader with each save. Saves for this comic can
     * reach the server out of order, so an older one must not overwrite a newer
     * page. The page number itself cannot serve as the counter because reading
     * backwards is legitimate.
     */
    #[ORM\Column(options: ['default' => 0])]
    private int $revision = 0;

    public function __construct()
    {
        $this->currentPage = 1;
        $this->lastReadAt = new \DateTimeImmutable();
        $this->completed = false;
    }

    #[ORM\PreUpdate]
    public function onUpdate(): void
    {
        $this->lastReadAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): static
    {
        $this->user = $user;

        return $this;
    }

    public function getComic(): ?Comic
    {
        return $this->comic;
    }

    public function setComic(?Comic $comic): static
    {
        $this->comic = $comic;

        return $this;
    }

    public function getCurrentPage(): ?int
    {
        return $this->currentPage;
    }

    public function setCurrentPage(int $currentPage): static
    {
        $this->currentPage = $currentPage;

        return $this;
    }

    public function getLastReadAt(): ?\DateTimeImmutable
    {
        return $this->lastReadAt;
    }

    public function setLastReadAt(\DateTimeImmutable $lastReadAt): static
    {
        $this->lastReadAt = $lastReadAt;

        return $this;
    }

    public function isCompleted(): ?bool
    {
        return $this->completed;
    }

    public function setCompleted(bool $completed): static
    {
        $this->completed = $completed;

        return $this;
    }

    public function getRevision(): int
    {
        return $this->revision;
    }

    public function setRevision(int $revision): static
    {
        $this->revision = $revision;

        return $this;
    }
}
