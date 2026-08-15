<?php

namespace App\Entity;

use App\Repository\ContentReportRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ContentReportRepository::class)]
#[ORM\Index(name: 'IDX_CONTENT_REPORT_STATUS_CREATED', columns: ['status', 'created_at'])]
#[ORM\Index(name: 'IDX_CONTENT_REPORT_CATEGORY', columns: ['category'])]
class ContentReport
{
    public const CATEGORY_COPYRIGHT = 'copyright_ip';
    public const CATEGORY_OTHER_ILLEGAL = 'other_illegal';
    public const CATEGORIES = [self::CATEGORY_COPYRIGHT, self::CATEGORY_OTHER_ILLEGAL];

    public const STATUS_RECEIVED = 'received';
    public const STATUS_UNDER_REVIEW = 'under_review';
    public const STATUS_AWAITING_INFORMATION = 'awaiting_information';
    public const STATUS_ACTION_TAKEN = 'action_taken';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_CLOSED = 'closed';
    public const STATUSES = [
        self::STATUS_RECEIVED,
        self::STATUS_UNDER_REVIEW,
        self::STATUS_AWAITING_INFORMATION,
        self::STATUS_ACTION_TAKEN,
        self::STATUS_REJECTED,
        self::STATUS_CLOSED,
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 200)]
    private string $reporterName;

    #[ORM\Column(length: 200, nullable: true)]
    private ?string $reporterOrganization = null;

    #[ORM\Column(length: 200, nullable: true)]
    private ?string $reporterRole = null;

    #[ORM\Column(length: 320)]
    private string $reporterEmail;

    #[ORM\Column(length: 32)]
    private string $category;

    #[ORM\Column(type: Types::TEXT)]
    private string $reportedReference;

    #[ORM\Column(type: Types::TEXT)]
    private string $explanation;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $goodFaithAcknowledgedAt;

    #[ORM\Column(length: 32)]
    private string $status = self::STATUS_RECEIVED;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $reviewedAt = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $reviewedByAdmin = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $resolutionCode = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $resolutionNote = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $linkedUser = null;

    #[ORM\ManyToOne(targetEntity: Comic::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Comic $linkedComic = null;

    #[ORM\ManyToOne(targetEntity: ComicShare::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?ComicShare $linkedShare = null;

    #[ORM\Column(options: ['default' => false])]
    private bool $legalHold = false;

    public function __construct(
        string $reporterName,
        string $reporterEmail,
        string $category,
        string $reportedReference,
        string $explanation,
    ) {
        $this->reporterName = $reporterName;
        $this->reporterEmail = mb_strtolower($reporterEmail);
        $this->category = $category;
        $this->reportedReference = $reportedReference;
        $this->explanation = $explanation;
        $this->goodFaithAcknowledgedAt = new \DateTimeImmutable();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function getId(): ?int { return $this->id; }
    public function getReporterName(): string { return $this->reporterName; }
    public function getReporterEmail(): string { return $this->reporterEmail; }
    public function getReporterOrganization(): ?string { return $this->reporterOrganization; }
    public function getReporterRole(): ?string { return $this->reporterRole; }
    public function getCategory(): string { return $this->category; }
    public function getReportedReference(): string { return $this->reportedReference; }
    public function getExplanation(): string { return $this->explanation; }
    public function getGoodFaithAcknowledgedAt(): \DateTimeImmutable { return $this->goodFaithAcknowledgedAt; }
    public function getStatus(): string { return $this->status; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }
    public function getReviewedAt(): ?\DateTimeImmutable { return $this->reviewedAt; }
    public function getReviewedByAdmin(): ?User { return $this->reviewedByAdmin; }
    public function getResolutionCode(): ?string { return $this->resolutionCode; }
    public function getResolutionNote(): ?string { return $this->resolutionNote; }
    public function getLinkedUser(): ?User { return $this->linkedUser; }
    public function getLinkedComic(): ?Comic { return $this->linkedComic; }
    public function getLinkedShare(): ?ComicShare { return $this->linkedShare; }
    public function isLegalHold(): bool { return $this->legalHold; }

    public function getReference(): string
    {
        return sprintf('CR-%s-%d', $this->createdAt->format('Ymd'), $this->id ?? 0);
    }

    public function setReporterOrganization(?string $organization): self
    {
        $this->reporterOrganization = $organization;
        return $this;
    }

    public function setReporterRole(?string $role): self
    {
        $this->reporterRole = $role;
        return $this;
    }

    public function reviewAs(User $admin, string $status): self
    {
        if (!in_array($status, self::STATUSES, true)) {
            throw new \DomainException('Invalid content report status.');
        }

        $this->status = $status;
        $this->reviewedByAdmin = $admin;
        $this->reviewedAt ??= new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    public function resolve(?string $code, ?string $note): self
    {
        $this->resolutionCode = $code;
        $this->resolutionNote = $note;
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    public function linkUser(?User $user): self { $this->linkedUser = $user; $this->updatedAt = new \DateTimeImmutable(); return $this; }
    public function linkComic(?Comic $comic): self { $this->linkedComic = $comic; $this->updatedAt = new \DateTimeImmutable(); return $this; }
    public function linkShare(?ComicShare $share): self { $this->linkedShare = $share; $this->updatedAt = new \DateTimeImmutable(); return $this; }
    public function setLegalHold(bool $legalHold): self { $this->legalHold = $legalHold; $this->updatedAt = new \DateTimeImmutable(); return $this; }
}
