<?php

namespace App\Entity;

use App\Repository\ShareClaimCodeRepository;
use App\Service\SharingCodeFormat;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * An offer of some comics to whoever the owner hands the code to.
 *
 * The counterpart to a receiver code, and the opposite direction: a receiver
 * code says "this is me, share with me", while this says "these are mine, come
 * and get them". It exists for the case where the owner does not know, and
 * should not have to ask for, the other person's address.
 *
 * It is a capability, so it is treated like one:
 *
 * - **Only the hash is stored.** The plaintext exists once, in the message the
 *   owner sends, exactly like an invitation link
 * - **It expires in a day.** A code pasted into a group chat is out of its
 *   owner's hands the moment it is sent; a short life is what stops it from
 *   still working weeks later
 * - **It is spent as it is used**, at most ten times and as few as one, so the
 *   owner decides up front how far it may travel
 * - **It grants nothing by itself.** Redeeming requires being signed in, and
 *   creates the same ordinary {@see ComicShare} an emailed invitation would.
 *   Every rule that follows — the 18+ gate, revocation, tombstones — is
 *   untouched by how the relationship started
 */
#[ORM\Entity(repositoryClass: ShareClaimCodeRepository::class)]
#[ORM\Table(name: 'share_claim_code')]
class ShareClaimCode
{
    /** How long an unredeemed code stays live. */
    public const TTL = '+1 day';

    public const MIN_USES = 1;
    public const MAX_USES = 10;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $owner = null;

    /** SHA-256 of the normalised plaintext. Unique, so a collision cannot hide. */
    #[ORM\Column(length: 64, unique: true)]
    private string $codeHash;

    /**
     * The comics on offer.
     *
     * Both sides of the join cascade, and only the join rows do. Deleting a
     * comic takes it off every code that offered it without destroying a code
     * that still carries others, and a code with nothing left on it stops being
     * redeemable on its own — so there is no second piece of state to keep in
     * step with a deletion, and nothing a stale code can hand out.
     */
    #[ORM\ManyToMany(targetEntity: Comic::class)]
    #[ORM\JoinTable(name: 'share_claim_code_comic')]
    #[ORM\JoinColumn(onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(onDelete: 'CASCADE')]
    private Collection $comics;

    #[ORM\Column]
    private int $maxUses;

    #[ORM\Column]
    private int $usesRemaining;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $expiresAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $revokedAt = null;

    /**
     * The same acknowledgement an emailed invitation records, taken when the
     * code is created because that is when the owner decides to hand the comics
     * out. Redemption cannot ask for it: the person redeeming is not the sender.
     */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $senderResponsibilityAcceptedAt;

    /**
     * @param list<Comic> $comics
     */
    public function __construct(User $owner, string $codeHash, array $comics, int $maxUses, \DateTimeImmutable $expiresAt)
    {
        $this->owner = $owner;
        $this->codeHash = $codeHash;
        $this->comics = new ArrayCollection($comics);
        $this->maxUses = $maxUses;
        $this->usesRemaining = $maxUses;
        $this->createdAt = new \DateTimeImmutable();
        $this->expiresAt = $expiresAt;
        $this->senderResponsibilityAcceptedAt = $this->createdAt;
    }

    public static function expiry(): \DateTimeImmutable
    {
        return (new \DateTimeImmutable())->modify(self::TTL);
    }

    /** Whether a count of uses is one this entity will accept. */
    public static function isUsableCount(int $maxUses): bool
    {
        return $maxUses >= self::MIN_USES && $maxUses <= self::MAX_USES;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOwner(): ?User
    {
        return $this->owner;
    }

    public function getCodeHash(): string
    {
        return $this->codeHash;
    }

    /** @return Collection<int, Comic> */
    public function getComics(): Collection
    {
        return $this->comics;
    }

    public function removeComic(Comic $comic): self
    {
        $this->comics->removeElement($comic);

        return $this;
    }

    public function getMaxUses(): int
    {
        return $this->maxUses;
    }

    public function getUsesRemaining(): int
    {
        return $this->usesRemaining;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getExpiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function getRevokedAt(): ?\DateTimeImmutable
    {
        return $this->revokedAt;
    }

    public function getSenderResponsibilityAcceptedAt(): \DateTimeImmutable
    {
        return $this->senderResponsibilityAcceptedAt;
    }

    public function isExpired(?\DateTimeImmutable $now = null): bool
    {
        return $this->expiresAt <= ($now ?? new \DateTimeImmutable());
    }

    /**
     * Every reason a code may be redeemed, in one place.
     *
     * A code with no comics left on it is spent as surely as one with no uses
     * left: every comic it offered has since been deleted, and redeeming it
     * would create nothing.
     */
    public function isRedeemable(?\DateTimeImmutable $now = null): bool
    {
        return $this->revokedAt === null
            && $this->usesRemaining > 0
            && !$this->comics->isEmpty()
            && !$this->isExpired($now);
    }

    /** Spend one use. Callers check {@see isRedeemable()} first. */
    public function spendUse(): self
    {
        $this->usesRemaining = max(0, $this->usesRemaining - 1);

        return $this;
    }

    public function revoke(): self
    {
        $this->revokedAt ??= new \DateTimeImmutable();

        return $this;
    }

    /**
     * What the owner is shown about a code they can no longer read.
     *
     * Never the code itself — that is gone the moment the response carrying it
     * is closed — only enough to recognise which offer this is and to withdraw
     * it.
     *
     * @return array<string, mixed>
     */
    public function toOwnerPayload(): array
    {
        $titles = [];
        foreach ($this->comics as $comic) {
            $titles[] = $comic->isExplicitContent()
                ? 'A comic marked as explicit content (18+)'
                : (string) $comic->getTitle();
        }

        return [
            'id' => $this->id,
            'comicTitles' => $titles,
            'comicCount' => count($titles),
            'maxUses' => $this->maxUses,
            'usesRemaining' => $this->usesRemaining,
            'createdAt' => $this->createdAt->format('c'),
            'expiresAt' => $this->expiresAt->format('c'),
            'isExpired' => $this->isExpired(),
            'isRedeemable' => $this->isRedeemable(),
        ];
    }

    /** The grouped form of a plaintext code, for the one response that shows it. */
    public static function forDisplay(string $plaintext): string
    {
        return SharingCodeFormat::forDisplay($plaintext);
    }
}
