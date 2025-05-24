<?php

namespace App\Entity;

use App\Repository\ShareTokenRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: ShareTokenRepository::class)]
#[ORM\HasLifecycleCallbacks]
class ShareToken
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 255, unique: true)]
    #[Assert\NotBlank]
    private string $token;

    #[ORM\ManyToOne(targetEntity: Comic::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Assert\NotNull]
    private Comic $comic;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Assert\NotNull]
    private User $sharedByUser;

    #[ORM\Column(type: 'string', length: 255)]
    #[Assert\NotBlank]
    #[Assert\Email]
    private string $sharedWithEmail;

    #[ORM\Column(type: 'datetime_immutable')]
    #[Assert\NotNull]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'boolean')]
    #[Assert\NotNull]
    private bool $isUsed;

    #[ORM\Column(type: 'datetime_immutable')]
    #[Assert\NotNull]
    private \DateTimeImmutable $expiresAt;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $publicCoverPath = null;

    public function __construct(Comic $comic, User $sharedByUser, string $sharedWithEmail)
    {
        $this->token = Uuid::v4()->toBase58();
        $this->comic = $comic;
        $this->sharedByUser = $sharedByUser;
        $this->sharedWithEmail = $sharedWithEmail;
        $this->createdAt = new \DateTimeImmutable();
        $this->isUsed = false;
        $this->expiresAt = (new \DateTimeImmutable())->modify('+7 days');
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getToken(): string
    {
        return $this->token;
    }

    public function getComic(): Comic
    {
        return $this->comic;
    }

    public function setComic(Comic $comic): self
    {
        $this->comic = $comic;
        return $this;
    }

    public function getSharedByUser(): User
    {
        return $this->sharedByUser;
    }

    public function setSharedByUser(User $sharedByUser): self
    {
        $this->sharedByUser = $sharedByUser;
        return $this;
    }

    public function getSharedWithEmail(): string
    {
        return $this->sharedWithEmail;
    }

    public function setSharedWithEmail(string $sharedWithEmail): self
    {
        $this->sharedWithEmail = $sharedWithEmail;
        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): self
    {
        $this->createdAt = $createdAt;
        return $this;
    }

    public function isIsUsed(): bool
    {
        return $this->isUsed;
    }

    public function setIsUsed(bool $isUsed): self
    {
        $this->isUsed = $isUsed;
        return $this;
    }

    public function getExpiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function setExpiresAt(\DateTimeImmutable $expiresAt): self
    {
        $this->expiresAt = $expiresAt;
        return $this;
    }

    public function getPublicCoverPath(): ?string
    {
        return $this->publicCoverPath;
    }

    public function setPublicCoverPath(?string $publicCoverPath): self
    {
        $this->publicCoverPath = $publicCoverPath;
        return $this;
    }
}
