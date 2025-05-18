<?php

namespace App\Entity;

use App\Repository\ComicRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: ComicRepository::class)]
#[ORM\HasLifecycleCallbacks]
class Comic
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    private ?string $title = null;

    #[ORM\Column(length: 1024)] // Assuming file paths can be long
    #[Assert\NotBlank]
    private ?string $filePath = null;

    #[ORM\Column(length: 1024, nullable: true)]
    private ?string $coverImagePath = null;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $pageCount = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $uploadedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $updatedAt = null;

    #[ORM\ManyToOne(inversedBy: 'comics')]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $owner = null;
    
    #[ORM\ManyToMany(targetEntity: Tag::class, inversedBy: 'comics')]
    private Collection $tags;
    
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;
    
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $author = null;
    
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $publisher = null;

    public function __construct()
    {
        $this->uploadedAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
        $this->tags = new ArrayCollection();
    }

    #[ORM\PreUpdate]
    public function preUpdate(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function setTitle(string $title): static
    {
        $this->title = $title;
        return $this;
    }

    public function getFilePath(): ?string
    {
        return $this->filePath;
    }

    public function setFilePath(string $filePath): static
    {
        $this->filePath = $filePath;
        return $this;
    }

    public function getCoverImagePath(): ?string
    {
        return $this->coverImagePath;
    }

    public function setCoverImagePath(?string $coverImagePath): static
    {
        $this->coverImagePath = $coverImagePath;
        return $this;
    }

    public function getPageCount(): ?int
    {
        return $this->pageCount;
    }

    public function setPageCount(?int $pageCount): static
    {
        $this->pageCount = $pageCount;
        return $this;
    }

    public function getUploadedAt(): ?\DateTimeImmutable
    {
        return $this->uploadedAt;
    }

    public function setUploadedAt(\DateTimeImmutable $uploadedAt): static
    {
        $this->uploadedAt = $uploadedAt;
        return $this;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(\DateTimeImmutable $updatedAt): static
    {
        $this->updatedAt = $updatedAt;
        return $this;
    }

    public function getOwner(): ?User
    {
        return $this->owner;
    }

    public function setOwner(?User $owner): static
    {
        $this->owner = $owner;
        return $this;
    }
    
    /**
     * @return Collection<int, Tag>
     */
    public function getTags(): Collection
    {
        return $this->tags;
    }

    public function addTag(Tag $tag): static
    {
        if (!$this->tags->contains($tag)) {
            $this->tags->add($tag);
        }

        return $this;
    }

    public function removeTag(Tag $tag): static
    {
        $this->tags->removeElement($tag);

        return $this;
    }
    
    public function getDescription(): ?string
    {
        return $this->description;
    }
    
    public function setDescription(?string $description): static
    {
        $this->description = $description;
        return $this;
    }
    
    public function getAuthor(): ?string
    {
        return $this->author;
    }
    
    public function setAuthor(?string $author): static
    {
        $this->author = $author;
        return $this;
    }
    
    public function getPublisher(): ?string
    {
        return $this->publisher;
    }
    
    public function setPublisher(?string $publisher): static
    {
        $this->publisher = $publisher;
        return $this;
    }
    
    /**
     * Convert the Comic entity to an array representation
     * 
     * @return array The comic data as an array
     */
    public function toArray(): array
    {
        $tagNames = [];
        foreach ($this->tags as $tag) {
            $tagNames[] = $tag->getName();
        }
        
        return [
            'id' => $this->id,
            'title' => $this->title,
            'filePath' => $this->filePath,
            'coverImagePath' => $this->coverImagePath,
            'pageCount' => $this->pageCount,
            'uploadedAt' => $this->uploadedAt ? $this->uploadedAt->format('c') : null,
            'updatedAt' => $this->updatedAt ? $this->updatedAt->format('c') : null,
            'author' => $this->author,
            'publisher' => $this->publisher,
            'description' => $this->description,
            'tags' => $tagNames
        ];
    }
}
