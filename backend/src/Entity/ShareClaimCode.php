<?php

namespace App\Entity;

use App\Enum\ShareCodeType;
use App\Repository\ShareClaimCodeRepository;
use App\Service\SharingCodeFormat;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * An offer of comics to whoever the owner hands the code to.
 *
 * The counterpart to a `U-` user code, and the opposite direction: a user code
 * says "this is me, share with me", while this says "these are mine, come and
 * get them". It exists for the case where the owner does not know, and should
 * not have to ask for, the other person's address.
 *
 * Two shapes, one row. A `C-` code carries exactly one comic and a `G-` code
 * carries a deliberate package of two to twenty — a story arc handed over as
 * one thing. They share a table because they share a lifecycle down to the last
 * rule; they do not share a prefix, because "how many comics is behind this?"
 * is the first thing anybody holding one wants to know.
 *
 * It is a capability, so it is treated like one:
 *
 * - **Only the hash is stored.** The plaintext exists once, in the message the
 *   owner sends, exactly like an invitation link
 * - **It expires**, after the operator's configured lifetime and seven days by
 *   default. A code pasted into a group chat is out of its owner's hands the
 *   moment it is sent; a bounded life is what stops it from still working
 *   months later
 * - **It is spent as it is used**, at most ten times and as few as one, so the
 *   owner decides up front how far it may travel. A group costs one use, not
 *   one per comic — the recipient took up the offer once
 * - **It grants nothing by itself.** Redeeming requires being signed in, and
 *   creates the same ordinary {@see ComicShare} an emailed invitation would.
 *   Every rule that follows — the 18+ gate, revocation, tombstones — is
 *   untouched by how the relationship started
 */
#[ORM\Entity(repositoryClass: ShareClaimCodeRepository::class)]
#[ORM\Table(name: 'share_claim_code')]
class ShareClaimCode
{
    /** The smallest package a `G-` code may carry. One comic is a `C-` code. */
    public const MIN_GROUP_COMICS = 2;

    /** And the largest, which is also the ceiling on a direct bulk share. */
    public const MAX_GROUP_COMICS = 20;

    /**
     * How long a dead code is kept before it is deleted.
     *
     * Measured from expiry, and it applies to a withdrawn code as much as to
     * one that simply ran out. The row is worthless as a code the moment it
     * dies — it cannot be redeemed again — but it is not worthless to its
     * owner, who is still looking at how many people took it up and which
     * comics went with it. A month is long enough to answer that; keeping them
     * for ever would just be a table that only grows.
     */
    public const RETENTION_AFTER_EXPIRY = '+30 days';

    public const MIN_USES = 1;
    public const MAX_USES = 10;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $owner = null;

    /**
     * Which kind of content code this is, as a fact about the row.
     *
     * Stored rather than derived from the comic count, because the count can
     * change underneath it: deleting a comic off a two-comic group would turn a
     * `G-` code into something that looked like a `C-` code and read back with
     * the wrong prefix. The type a code was issued as is the type it stays.
     */
    #[ORM\Column(length: 1, enumType: ShareCodeType::class)]
    private ShareCodeType $type;

    /**
     * SHA-256 of the type and the normalised token together. Unique, so a
     * collision cannot hide, and typed, so a `C-` code cannot be redeemed as
     * the `G-` code that drew the same twelve characters.
     */
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

    /**
     * How many comics were on this code when it was issued.
     *
     * The join rows cascade away with the comics, so the collection above
     * silently shrinks when an owner deletes something they had put behind a
     * code. For a `C-` code that empties it, which stops it dead. For a `G-`
     * code it would quietly turn a fifteen-issue arc into a fourteen-issue one,
     * and the recipient would have no way to know what they were missing —
     * so the issued count is written down and a group that no longer matches it
     * is not redeemable at all.
     */
    #[ORM\Column(options: ['default' => 0])]
    private int $issuedComicCount = 0;

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
    public function __construct(
        User $owner,
        ShareCodeType $type,
        string $codeHash,
        array $comics,
        int $maxUses,
        \DateTimeImmutable $expiresAt
    ) {
        $this->owner = $owner;
        $this->type = $type;
        $this->codeHash = $codeHash;
        $this->comics = new ArrayCollection($comics);
        $this->issuedComicCount = count($comics);
        $this->maxUses = $maxUses;
        $this->usesRemaining = $maxUses;
        $this->createdAt = new \DateTimeImmutable();
        $this->expiresAt = $expiresAt;
        $this->senderResponsibilityAcceptedAt = $this->createdAt;
    }

    /** Whether a count of uses is one this entity will accept. */
    public static function isUsableCount(int $maxUses): bool
    {
        return $maxUses >= self::MIN_USES && $maxUses <= self::MAX_USES;
    }

    /**
     * Whether this many comics is a legal package for that kind of code.
     *
     * The invariant the two prefixes exist to promise: a `C-` code is one comic
     * and a `G-` code is a group. Stated once, here, so the creation endpoints
     * and the tests are reading the same rule.
     */
    public static function isUsableComicCount(ShareCodeType $type, int $count): bool
    {
        return match ($type) {
            ShareCodeType::COMIC => $count === 1,
            ShareCodeType::GROUP => $count >= self::MIN_GROUP_COMICS && $count <= self::MAX_GROUP_COMICS,
            ShareCodeType::USER => false,
        };
    }

    /** Why that many comics is not a package of this kind, or null. */
    public static function describeComicCountProblem(ShareCodeType $type): string
    {
        return match ($type) {
            ShareCodeType::COMIC => 'A comic code carries exactly one comic.',
            ShareCodeType::GROUP => sprintf(
                'A group code carries between %d and %d comics.',
                self::MIN_GROUP_COMICS,
                self::MAX_GROUP_COMICS
            ),
            ShareCodeType::USER => 'A user code does not carry comics.',
        };
    }

    public function getType(): ShareCodeType
    {
        return $this->type;
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

    public function getIssuedComicCount(): int
    {
        return $this->issuedComicCount;
    }

    /**
     * Whether the package this code was issued for is still whole.
     *
     * All or nothing, for a group especially: somebody redeeming a fifteen-issue
     * arc is taking up an offer of fifteen issues, and handing them fourteen
     * without saying so is worse than handing them nothing. The owner's way out
     * is to withdraw the code and issue a new one for what they still have.
     */
    public function isPackageIntact(): bool
    {
        return $this->comics->count() === $this->issuedComicCount;
    }

    /**
     * Every reason a code may be redeemed, in one place.
     *
     * A code whose package is no longer whole is spent as surely as one with no
     * uses left: for a `C-` code the comic it offered has been deleted and
     * redeeming it would create nothing, and for a `G-` code the arc it
     * promised cannot be delivered.
     */
    public function isRedeemable(?\DateTimeImmutable $now = null): bool
    {
        return $this->revokedAt === null
            && $this->usesRemaining > 0
            && $this->isPackageIntact()
            && !$this->comics->isEmpty()
            && !$this->isExpired($now);
    }

    /** Spend one use. Callers check {@see isRedeemable()} first. */
    public function spendUse(): self
    {
        $this->usesRemaining = max(0, $this->usesRemaining - 1);

        return $this;
    }

    /**
     * Withdraw the code before it would have died on its own.
     *
     * Idempotent, so withdrawing twice keeps the moment it actually happened.
     * The shares it already produced are untouched: those are ordinary
     * relationships now, revoked from the Sharing page like any other.
     */
    public function revoke(): self
    {
        $this->revokedAt ??= new \DateTimeImmutable();

        return $this;
    }

    public function isRevoked(): bool
    {
        return $this->revokedAt !== null;
    }

    /** Why this code can no longer be used, for the owner's list. */
    public function deadReason(): ?string
    {
        if ($this->revokedAt !== null) {
            return 'withdrawn';
        }
        if ($this->isExpired()) {
            return 'expired';
        }
        if ($this->usesRemaining <= 0) {
            return 'used_up';
        }
        if (!$this->isPackageIntact()) {
            return 'comics_removed';
        }

        return null;
    }

    /** When this row becomes rubbish worth deleting. */
    public function deletableAfter(): \DateTimeImmutable
    {
        return $this->expiresAt->modify(self::RETENTION_AFTER_EXPIRY);
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
            'type' => $this->type->value,
            'comicTitles' => $titles,
            'comicCount' => count($titles),
            // What the owner offered, which is what their list should say even
            // after one of the comics behind it has gone.
            'issuedComicCount' => $this->issuedComicCount,
            'maxUses' => $this->maxUses,
            'usesRemaining' => $this->usesRemaining,
            // How many people took it up, which is the question the owner is
            // actually asking when they look at this list.
            'timesUsed' => $this->maxUses - $this->usesRemaining,
            'createdAt' => $this->createdAt->format('c'),
            'expiresAt' => $this->expiresAt->format('c'),
            'isExpired' => $this->isExpired(),
            'isRevoked' => $this->isRevoked(),
            'isRedeemable' => $this->isRedeemable(),
            'deadReason' => $this->deadReason(),
            'deletableAfter' => $this->deletableAfter()->format('c'),
        ];
    }

    /**
     * What an administrator is shown about a code somebody else issued.
     *
     * The owner's payload plus the operational metadata support needs to act on
     * a report: whose code it is, and which comics are behind it named rather
     * than redacted. The 18+ redaction the owner's view applies is for the
     * *recipient's* benefit and has no meaning here — an administrator handling
     * an abuse report is precisely the person who needs to know what was being
     * handed out.
     *
     * Still never the code. Only its hash is stored, so there is nothing to
     * show and nothing to recover.
     *
     * @return array<string, mixed>
     */
    public function toAdminPayload(): array
    {
        $comics = [];
        foreach ($this->comics as $comic) {
            $comics[] = [
                'id' => $comic->getId(),
                'title' => $comic->getTitle(),
                'explicitContent' => $comic->isExplicitContent(),
            ];
        }

        return $this->toOwnerPayload() + [
            'ownerId' => $this->owner?->getId(),
            'ownerName' => $this->owner?->getName(),
            'ownerEmail' => $this->owner?->getEmail(),
            'comics' => $comics,
            'revokedAt' => $this->revokedAt?->format('c'),
        ];
    }

    /** The grouped, prefixed form of a token, for the one response that shows it. */
    public static function forDisplay(ShareCodeType $type, string $token): string
    {
        return SharingCodeFormat::forDisplay($type, $token);
    }
}
