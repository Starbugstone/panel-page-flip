<?php

namespace App\Entity;

use App\Enum\ComicSourceType;
use App\Enum\ReadingDirection;
use App\Metadata\ComicPageInfo;
use App\Repository\ComicRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: ComicRepository::class)]
#[ORM\Index(name: 'IDX_comic_owner_dropbox_path', columns: ['owner_id', 'dropbox_path'])]
#[ORM\Index(name: 'IDX_comic_owner_series', columns: ['owner_id', 'series'])]
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

    #[ORM\Column(enumType: ComicSourceType::class, options: ['default' => 'cbz'])]
    private ComicSourceType $sourceType = ComicSourceType::CBZ;

    #[ORM\Column(length: 1024, nullable: true)]
    private ?string $coverImagePath = null;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $pageCount = null;

    #[ORM\Column(type: Types::BIGINT, nullable: true)]
    private ?int $fileSize = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $uploadedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $updatedAt = null;

    #[ORM\ManyToOne(inversedBy: 'comics')]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $owner = null;
    
    #[ORM\ManyToMany(targetEntity: Tag::class, inversedBy: 'comics')]
    private Collection $tags;
    
    #[ORM\OneToMany(mappedBy: 'comic', targetEntity: ComicReadingProgress::class, cascade: ['remove'])]
    private Collection $readingProgresses;
    
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;
    
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $author = null;
    
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $publisher = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $series = null;

    /** A string, not a number: "1.5", "0", and "Annual 2" are all real issue numbers. */
    #[ORM\Column(length: 50, nullable: true)]
    private ?string $issueNumber = null;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $issueCount = null;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $volume = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $publishedAt = null;

    #[ORM\Column(length: 16, nullable: true)]
    private ?string $languageCode = null;

    #[ORM\Column(length: 32, nullable: true)]
    private ?string $ageRating = null;

    /**
     * What the file says about its own reading order. The reader's own persisted
     * settings stay authoritative; this is the per-comic default they start from.
     */
    #[ORM\Column(enumType: ReadingDirection::class, options: ['default' => 'ltr'])]
    private ReadingDirection $readingDirection = ReadingDirection::LeftToRight;

    /** @var array<string, list<string>>|null role => names */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $creators = null;

    /**
     * Per-page facts known without decoding the images: page type, double-page
     * flags and dimensions. Stored whole because every consumer wants the whole
     * set at once, and queried through getPageInfo().
     *
     * @var list<array<string, mixed>>|null
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $pageMetadata = null;

    /**
     * Source path in the owner's Dropbox, set only for comics pulled in by the
     * Dropbox integration. Used to skip files that have already been imported.
     *
     * Kept at 500 characters so (owner_id, dropbox_path) fits inside MySQL's
     * utf8mb4 index key limit; real Dropbox paths are far shorter.
     */
    #[ORM\Column(length: 500, nullable: true)]
    private ?string $dropboxPath = null;

    /**
     * The owner's declaration that this comic contains adult material.
     *
     * Deliberately independent of every tag, including the ones that hide a
     * comic from the library: hiding is a shelving preference and says nothing
     * about content, so inferring an age rating from it would put an 18+ warning
     * on a comic somebody merely wanted out of the way. Only the owner ticking
     * the box sets this.
     */
    #[ORM\Column(options: ['default' => false])]
    private bool $explicitContent = false;

    public function __construct()
    {
        $this->uploadedAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
        $this->tags = new ArrayCollection();
        $this->readingProgresses = new ArrayCollection();
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

    public function getSourceType(): ComicSourceType
    {
        return $this->sourceType;
    }

    public function setSourceType(ComicSourceType $sourceType): static
    {
        $this->sourceType = $sourceType;
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

    public function getFileSize(): ?int
    {
        return $this->fileSize;
    }

    public function setFileSize(?int $fileSize): static
    {
        $this->fileSize = $fileSize;
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

    public function getSeries(): ?string
    {
        return $this->series;
    }

    public function setSeries(?string $series): static
    {
        $this->series = $series;

        return $this;
    }

    public function getIssueNumber(): ?string
    {
        return $this->issueNumber;
    }

    public function setIssueNumber(?string $issueNumber): static
    {
        $this->issueNumber = $issueNumber;

        return $this;
    }

    public function getIssueCount(): ?int
    {
        return $this->issueCount;
    }

    public function setIssueCount(?int $issueCount): static
    {
        $this->issueCount = $issueCount;

        return $this;
    }

    public function getVolume(): ?int
    {
        return $this->volume;
    }

    public function setVolume(?int $volume): static
    {
        $this->volume = $volume;

        return $this;
    }

    public function getPublishedAt(): ?\DateTimeImmutable
    {
        return $this->publishedAt;
    }

    public function setPublishedAt(?\DateTimeImmutable $publishedAt): static
    {
        $this->publishedAt = $publishedAt;

        return $this;
    }

    public function getLanguageCode(): ?string
    {
        return $this->languageCode;
    }

    public function setLanguageCode(?string $languageCode): static
    {
        $this->languageCode = $languageCode;

        return $this;
    }

    public function getAgeRating(): ?string
    {
        return $this->ageRating;
    }

    public function setAgeRating(?string $ageRating): static
    {
        $this->ageRating = $ageRating;

        return $this;
    }

    public function getReadingDirection(): ReadingDirection
    {
        return $this->readingDirection;
    }

    public function setReadingDirection(ReadingDirection $readingDirection): static
    {
        $this->readingDirection = $readingDirection;

        return $this;
    }

    /** @return array<string, list<string>> */
    public function getCreators(): array
    {
        return $this->creators ?? [];
    }

    /** @param array<string, list<string>> $creators */
    public function setCreators(array $creators): static
    {
        $this->creators = $creators === [] ? null : $creators;

        return $this;
    }

    /** @return list<array<string, mixed>> */
    public function getPageMetadata(): array
    {
        return $this->pageMetadata ?? [];
    }

    /** @param list<array<string, mixed>> $pageMetadata */
    public function setPageMetadata(array $pageMetadata): static
    {
        $this->pageMetadata = $pageMetadata === [] ? null : $pageMetadata;

        return $this;
    }

    /**
     * Page facts keyed by 1-based page number.
     *
     * This is what spread pairing and page-derivative work read; stored values
     * are normalised on the way out, so data written by an older version cannot
     * reach a consumer in a shape it does not expect.
     *
     * @return array<int, ComicPageInfo>
     */
    public function getPageInfo(): array
    {
        $info = [];

        foreach ($this->getPageMetadata() as $stored) {
            $page = is_array($stored) ? ComicPageInfo::fromArray($stored) : null;
            if ($page !== null) {
                $info[$page->page] = $page;
            }
        }

        return $info;
    }

    public function getDropboxPath(): ?string
    {
        return $this->dropboxPath;
    }

    public function setDropboxPath(?string $dropboxPath): static
    {
        $this->dropboxPath = $dropboxPath;

        return $this;
    }

    public function isExplicitContent(): bool
    {
        return $this->explicitContent;
    }

    public function setExplicitContent(bool $explicitContent): static
    {
        $this->explicitContent = $explicitContent;

        return $this;
    }

    /**
     * @return Collection<int, ComicReadingProgress>
     */
    public function getReadingProgresses(): Collection
    {
        return $this->readingProgresses;
    }

    public function addReadingProgress(ComicReadingProgress $readingProgress): static
    {
        if (!$this->readingProgresses->contains($readingProgress)) {
            $this->readingProgresses->add($readingProgress);
            $readingProgress->setComic($this);
        }

        return $this;
    }

    public function removeReadingProgress(ComicReadingProgress $readingProgress): static
    {
        if ($this->readingProgresses->removeElement($readingProgress)) {
            // set the owning side to null (unless already changed)
            if ($readingProgress->getComic() === $this) {
                $readingProgress->setComic(null);
            }
        }

        return $this;
    }
}
