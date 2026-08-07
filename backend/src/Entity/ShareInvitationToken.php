<?php

namespace App\Entity;

use App\Repository\ShareInvitationTokenRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One emailed invitation link for a {@see ComicShare}.
 *
 * Kept separate from the access relationship so the two lifecycles do not fight
 * each other: resending mints a new token and invalidates the old link, while
 * access that has already been accepted survives both.
 *
 * Only a hash is stored. The plaintext exists once, in the email that carries
 * it, so a database leak cannot be replayed into somebody's library.
 */
#[ORM\Entity(repositoryClass: ShareInvitationTokenRepository::class)]
#[ORM\Table(name: 'share_invitation_token')]
class ShareInvitationToken
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: ComicShare::class, inversedBy: 'invitationTokens')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ComicShare $comicShare;

    #[ORM\Column(length: 64, unique: true)]
    private string $tokenHash;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $expiresAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $usedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $revokedAt = null;

    public function __construct(ComicShare $comicShare, string $tokenHash, \DateTimeImmutable $expiresAt)
    {
        $this->comicShare = $comicShare;
        $this->tokenHash = $tokenHash;
        $this->expiresAt = $expiresAt;
        $this->createdAt = new \DateTimeImmutable();
        $comicShare->addInvitationToken($this);
    }

    /**
     * A plaintext token and the hash to store for it.
     *
     * SHA-256 without a work factor is the right choice here: the token is 256
     * bits of randomness rather than a human-chosen secret, so there is no
     * dictionary to slow an attacker down through.
     *
     * @return array{0: string, 1: string} plaintext, hash
     */
    public static function generate(): array
    {
        $plaintext = bin2hex(random_bytes(32));

        return [$plaintext, self::hash($plaintext)];
    }

    public static function hash(string $plaintext): string
    {
        return hash('sha256', $plaintext);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getComicShare(): ComicShare
    {
        return $this->comicShare;
    }

    public function getTokenHash(): string
    {
        return $this->tokenHash;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getExpiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function getUsedAt(): ?\DateTimeImmutable
    {
        return $this->usedAt;
    }

    public function getRevokedAt(): ?\DateTimeImmutable
    {
        return $this->revokedAt;
    }

    public function markUsed(): self
    {
        $this->usedAt = new \DateTimeImmutable();

        return $this;
    }

    public function revoke(): self
    {
        if ($this->revokedAt === null) {
            $this->revokedAt = new \DateTimeImmutable();
        }

        return $this;
    }

    public function isUsable(?\DateTimeImmutable $now = null): bool
    {
        $now ??= new \DateTimeImmutable();

        return $this->usedAt === null
            && $this->revokedAt === null
            && $this->expiresAt > $now;
    }
}
