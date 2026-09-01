<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\UserOAuthIdentityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A provider account linked to a local user.
 *
 * The provider subject is the identity. The email is only a display snapshot:
 * provider emails can change and must never decide which local account owns a
 * login.
 */
#[ORM\Entity(repositoryClass: UserOAuthIdentityRepository::class)]
#[ORM\Table(name: 'user_oauth_identity')]
#[ORM\Index(name: 'IDX_OAUTH_IDENTITY_USER', columns: ['user_id'])]
#[ORM\UniqueConstraint(name: 'uniq_oauth_provider_subject', columns: ['provider', 'provider_subject'])]
#[ORM\UniqueConstraint(name: 'uniq_oauth_user_provider', columns: ['user_id', 'provider'])]
class UserOAuthIdentity
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    protected ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'oauthIdentities')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 32)]
    private string $provider;

    #[ORM\Column(name: 'provider_subject', length: 255, options: ['collation' => 'utf8mb4_bin'])]
    private string $providerSubject;

    #[ORM\Column(name: 'provider_email', length: 180, nullable: true)]
    private ?string $providerEmail = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastUsedAt = null;

    public function __construct(User $user, string $provider, string $providerSubject, ?string $providerEmail = null)
    {
        $this->user = $user;
        $this->provider = strtolower(trim($provider));
        $this->providerSubject = trim($providerSubject);
        $this->providerEmail = self::normaliseEmail($providerEmail);
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getProvider(): string
    {
        return $this->provider;
    }

    public function getProviderSubject(): string
    {
        return $this->providerSubject;
    }

    public function getProviderEmail(): ?string
    {
        return $this->providerEmail;
    }

    public function setProviderEmail(?string $providerEmail): self
    {
        $this->providerEmail = self::normaliseEmail($providerEmail);

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getLastUsedAt(): ?\DateTimeImmutable
    {
        return $this->lastUsedAt;
    }

    public function markUsed(): self
    {
        $this->lastUsedAt = new \DateTimeImmutable();

        return $this;
    }

    private static function normaliseEmail(?string $email): ?string
    {
        $email = $email === null ? '' : trim($email);

        return $email === '' ? null : $email;
    }
}
