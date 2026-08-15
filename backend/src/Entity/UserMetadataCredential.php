<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\UserMetadataCredentialRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * One user's own provider credentials.
 *
 * Write-only after saving: the user is told whether a token is configured and
 * when it was last changed, never what it is. Encrypted at rest by
 * MetadataProviderSecretsSubscriber and removed with the account, which is what
 * makes offering the field defensible in the first place.
 *
 * A personal credential is preferred over the installation's shared one, so a
 * user who brings their own does not spend anybody else's allowance.
 */
#[ORM\Entity(repositoryClass: UserMetadataCredentialRepository::class)]
#[ORM\Table(name: 'user_metadata_credential')]
class UserMetadataCredential
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /**
     * Owning side only, with no inverse property on User.
     *
     * Doctrine cannot lazy-load the inverse side of a one-to-one, so a property
     * on User would make every user hydration issue an extra query — including
     * the admin list, which loads them by the page. It is fetched deliberately
     * through UserMetadataCredentialService instead.
     */
    #[ORM\OneToOne]
    #[ORM\JoinColumn(nullable: false, unique: true, onDelete: 'CASCADE')]
    private ?User $user = null;

    #[ORM\Column(length: 1024, nullable: true)]
    private ?string $metronToken = null;

    #[ORM\Column(length: 1024, nullable: true)]
    private ?string $comicVineApiKey = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->updatedAt = new \DateTimeImmutable();
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

    public function getMetronToken(): ?string
    {
        return $this->metronToken;
    }

    public function setMetronToken(?string $metronToken): static
    {
        $this->metronToken = self::blankToNull($metronToken);

        return $this;
    }

    public function getComicVineApiKey(): ?string
    {
        return $this->comicVineApiKey;
    }

    public function setComicVineApiKey(?string $comicVineApiKey): static
    {
        $this->comicVineApiKey = self::blankToNull($comicVineApiKey);

        return $this;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function touch(): static
    {
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    public function isEmpty(): bool
    {
        return $this->metronToken === null && $this->comicVineApiKey === null;
    }

    private static function blankToNull(?string $value): ?string
    {
        $value = $value === null ? null : trim($value);

        return $value === '' ? null : $value;
    }
}
