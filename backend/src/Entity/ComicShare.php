<?php

namespace App\Entity;

use App\Repository\ComicShareRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A durable grant of read access to one comic, for one recipient.
 *
 * This is the whole of what sharing is: no second Comic row, no second CBZ. The
 * owner keeps the only file, and this record decides who else is allowed to
 * read it.
 *
 * The record outlives the comic on purpose. When the original goes away the
 * relationship is tombstoned rather than deleted, so a recipient who had
 * accepted it is told why it disappeared instead of watching it vanish.
 */
#[ORM\Entity(repositoryClass: ComicShareRepository::class)]
#[ORM\Table(name: 'comic_share')]
// One durable relationship per comic and recipient. Re-inviting somebody reuses
// the existing row, which is what stops a comic accumulating five pending
// invitations for the same address. Tombstoned rows have a null comic, and
// MySQL lets any number of those share the index.
#[ORM\UniqueConstraint(name: 'UNIQ_comic_share_comic_recipient', columns: ['comic_id', 'recipient_email_normalized'])]
#[ORM\Index(name: 'IDX_comic_share_recipient_status', columns: ['recipient_email_normalized', 'status'])]
#[ORM\Index(name: 'IDX_comic_share_owner', columns: ['owner_id'])]
class ComicShare
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_ACCEPTED = 'accepted';
    public const STATUS_DECLINED = 'declined';
    public const STATUS_REVOKED = 'revoked';

    public const REASON_OWNER_DELETED = 'owner_deleted';
    public const REASON_OWNER_ACCOUNT_DELETED = 'owner_account_deleted';
    public const REASON_FILE_MISSING = 'file_missing';
    public const REASON_ADMINISTRATIVELY_REMOVED = 'administratively_removed';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /**
     * Nulled when the comic is gone, which is what turns this row into a
     * tombstone. SET NULL rather than CASCADE so a deletion that bypasses the
     * application still leaves the history behind.
     */
    #[ORM\ManyToOne(targetEntity: Comic::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Comic $comic = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $owner = null;

    /**
     * Set once the invitation is accepted by a signed-in account. Until then
     * only the email address is known, because the recipient may not have
     * registered yet.
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $recipientUser = null;

    /** Always trimmed and lowercased — see {@see normaliseEmail()}. */
    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    #[Assert\Email]
    private string $recipientEmailNormalized;

    #[ORM\Column(length: 20)]
    #[Assert\Choice(choices: [self::STATUS_PENDING, self::STATUS_ACCEPTED, self::STATUS_DECLINED, self::STATUS_REVOKED])]
    private string $status = self::STATUS_PENDING;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $acceptedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $declinedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $revokedAt = null;

    /**
     * The recipient hid the comic from their own collection. Access is
     * untouched, so restoring it is a single click for as long as the owner
     * keeps sharing.
     */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $recipientRemovedAt = null;

    /** Bounds how long an unanswered invitation stays open. Cleared on accept. */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $expiresAt = null;

    #[ORM\Column(length: 255)]
    private string $comicTitleSnapshot = '';

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $comicAuthorSnapshot = null;

    #[ORM\Column(length: 255)]
    private string $ownerNameSnapshot = '';

    /**
     * Set when the sender reached this recipient through their receiver code
     * rather than by typing their address.
     *
     * The point of a receiver code is that the sender never learns the address,
     * so the address they never learned must not be handed back to them by the
     * page that lists what they shared. These two carry what the owner is shown
     * instead: the recipient's name as it was, and the code they can use to
     * offer them something else.
     *
     * Both null for an ordinary email invitation, where the sender typed the
     * address themselves and there is nothing to withhold.
     */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $recipientAliasName = null;

    #[ORM\Column(length: 16, nullable: true)]
    private ?string $recipientSharingCode = null;

    /**
     * Whether the comic was marked explicit when the snapshots were last taken.
     *
     * Kept alongside the title snapshot because a tombstone outlives the comic
     * that would otherwise answer the question — and the title it preserves is
     * exactly the identifying detail an unconfirmed age gate is holding back.
     * Deleting the comic must not be the way that title gets out.
     */
    #[ORM\Column(options: ['default' => false])]
    private bool $explicitContentSnapshot = false;

    /**
     * When the sender accepted responsibility for what they were sharing.
     *
     * Per share rather than per account: the acknowledgement is about this
     * comic going to this person, so a blanket "I understand" ticked once a year
     * ago would not be a record of anything. Null only on shares created before
     * the acknowledgement existed.
     */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $senderResponsibilityAcceptedAt = null;

    /**
     * When the recipient declared they are 18 or older, for a comic the owner
     * marked explicit. Null means the gate is still closed, which is the state
     * an explicit share starts in and returns to whenever the comic is newly
     * marked explicit.
     */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $adultConfirmedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $unavailableAt = null;

    #[ORM\Column(length: 40, nullable: true)]
    private ?string $tombstoneReason = null;

    /**
     * @var Collection<int, ShareInvitationToken>
     */
    #[ORM\OneToMany(mappedBy: 'comicShare', targetEntity: ShareInvitationToken::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $invitationTokens;

    public function __construct(Comic $comic, User $owner, string $recipientEmail)
    {
        $this->comic = $comic;
        $this->owner = $owner;
        $this->recipientEmailNormalized = self::normaliseEmail($recipientEmail);
        $this->createdAt = new \DateTimeImmutable();
        $this->invitationTokens = new ArrayCollection();
        $this->refreshSnapshots();
    }

    /**
     * Trim plus lowercase, so "  Jane@Example.COM " and "jane@example.com" are
     * one recipient for the uniqueness constraint and for every lookup.
     */
    public static function normaliseEmail(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    /**
     * Copy the metadata a tombstone will need while the comic is still here.
     * Called whenever an invitation is (re)sent, so the snapshot reflects the
     * comic as the recipient was invited to it.
     */
    public function refreshSnapshots(): self
    {
        if ($this->comic !== null) {
            $this->comicTitleSnapshot = (string) $this->comic->getTitle();
            $this->comicAuthorSnapshot = $this->comic->getAuthor();
            $this->explicitContentSnapshot = $this->comic->isExplicitContent();
        }

        if ($this->owner !== null) {
            $this->ownerNameSnapshot = (string) ($this->owner->getName() ?? $this->owner->getEmail());
        }

        return $this;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    /**
     * Record that this relationship was made through a receiver code.
     *
     * Called with the recipient's own name and code, both of which they published
     * by handing the code out. Nothing else about them crosses over.
     */
    public function hideRecipientBehindSharingCode(string $sharingCode, ?string $recipientName): self
    {
        $this->recipientSharingCode = $sharingCode;
        $this->recipientAliasName = $recipientName;

        return $this;
    }

    /**
     * Stop hiding the address, because the owner supplied it themselves.
     *
     * Re-inviting reuses the row, so a relationship that began with a receiver
     * code can be reopened by somebody typing the address — at which point
     * withholding it would be withholding something they already have.
     */
    public function revealRecipientAddressToOwner(): self
    {
        $this->recipientSharingCode = null;
        $this->recipientAliasName = null;

        return $this;
    }

    /** Whether the owner may be shown this recipient's address. */
    public function isRecipientAddressHiddenFromOwner(): bool
    {
        return $this->recipientSharingCode !== null;
    }

    public function getRecipientAliasName(): ?string
    {
        return $this->recipientAliasName;
    }

    public function getRecipientSharingCode(): ?string
    {
        return $this->recipientSharingCode;
    }

    public function getComic(): ?Comic
    {
        return $this->comic;
    }

    public function setComic(?Comic $comic): self
    {
        $this->comic = $comic;

        return $this;
    }

    public function getOwner(): ?User
    {
        return $this->owner;
    }

    public function setOwner(?User $owner): self
    {
        $this->owner = $owner;

        return $this;
    }

    public function getRecipientUser(): ?User
    {
        return $this->recipientUser;
    }

    public function setRecipientUser(?User $recipientUser): self
    {
        $this->recipientUser = $recipientUser;

        return $this;
    }

    public function getRecipientEmailNormalized(): string
    {
        return $this->recipientEmailNormalized;
    }

    public function setRecipientEmail(string $email): self
    {
        $this->recipientEmailNormalized = self::normaliseEmail($email);

        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getAcceptedAt(): ?\DateTimeImmutable
    {
        return $this->acceptedAt;
    }

    public function getDeclinedAt(): ?\DateTimeImmutable
    {
        return $this->declinedAt;
    }

    public function getRevokedAt(): ?\DateTimeImmutable
    {
        return $this->revokedAt;
    }

    public function getRecipientRemovedAt(): ?\DateTimeImmutable
    {
        return $this->recipientRemovedAt;
    }

    public function getExpiresAt(): ?\DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function setExpiresAt(?\DateTimeImmutable $expiresAt): self
    {
        $this->expiresAt = $expiresAt;

        return $this;
    }

    public function getComicTitleSnapshot(): string
    {
        return $this->comicTitleSnapshot;
    }

    public function getComicAuthorSnapshot(): ?string
    {
        return $this->comicAuthorSnapshot;
    }

    public function getOwnerNameSnapshot(): string
    {
        return $this->ownerNameSnapshot;
    }

    public function getSenderResponsibilityAcceptedAt(): ?\DateTimeImmutable
    {
        return $this->senderResponsibilityAcceptedAt;
    }

    /**
     * Record the sender's acknowledgement, now.
     *
     * The timestamp is taken here and never read off the request: an audit trail
     * the audited party can write is not one.
     */
    public function acceptSenderResponsibility(): self
    {
        $this->senderResponsibilityAcceptedAt = new \DateTimeImmutable();

        return $this;
    }

    public function getAdultConfirmedAt(): ?\DateTimeImmutable
    {
        return $this->adultConfirmedAt;
    }

    /**
     * Record the recipient's age declaration, once.
     *
     * Idempotent on purpose: confirming twice is a double click or a retried
     * request, and neither is a reason to move the moment the declaration was
     * actually made.
     */
    public function confirmAdult(): self
    {
        $this->adultConfirmedAt ??= new \DateTimeImmutable();

        return $this;
    }

    /**
     * Close the age gate again.
     *
     * Used when a comic that was already shared becomes explicit: the recipient
     * agreed to read something that was not marked 18+, so their earlier silence
     * cannot stand in for a declaration about the comic it is now.
     */
    public function resetAdultConfirmation(): self
    {
        $this->adultConfirmedAt = null;

        return $this;
    }

    /**
     * Whether the comic behind this share is classified 18+.
     *
     * Answered by the comic while there is one, and by the snapshot once there
     * is not — so a tombstone still knows what it is a tombstone of.
     */
    public function isExplicitContent(): bool
    {
        return $this->comic?->isExplicitContent() ?? $this->explicitContentSnapshot;
    }

    /**
     * Whether this share is currently waiting on the recipient's age
     * declaration. False for anything that is not explicit, so the ordinary
     * flow never notices this exists.
     */
    public function requiresAdultConfirmation(): bool
    {
        return $this->isExplicitContent() && $this->adultConfirmedAt === null;
    }

    public function getUnavailableAt(): ?\DateTimeImmutable
    {
        return $this->unavailableAt;
    }

    public function getTombstoneReason(): ?string
    {
        return $this->tombstoneReason;
    }

    /**
     * @return Collection<int, ShareInvitationToken>
     */
    public function getInvitationTokens(): Collection
    {
        return $this->invitationTokens;
    }

    public function addInvitationToken(ShareInvitationToken $token): self
    {
        if (!$this->invitationTokens->contains($token)) {
            $this->invitationTokens->add($token);
        }

        return $this;
    }

    /**
     * Reopen a relationship that was declined, revoked or never answered.
     * Re-inviting is the only way back into pending, and it always starts a
     * fresh clock.
     */
    public function markPending(\DateTimeImmutable $expiresAt): self
    {
        $this->status = self::STATUS_PENDING;
        $this->expiresAt = $expiresAt;
        $this->acceptedAt = null;
        $this->declinedAt = null;
        $this->revokedAt = null;
        $this->recipientRemovedAt = null;

        return $this;
    }

    public function markAccepted(User $recipient): self
    {
        $this->status = self::STATUS_ACCEPTED;
        $this->recipientUser = $recipient;
        $this->acceptedAt = new \DateTimeImmutable();
        $this->declinedAt = null;
        $this->revokedAt = null;
        $this->recipientRemovedAt = null;
        // Access does not expire; only the unanswered invitation did.
        $this->expiresAt = null;

        return $this;
    }

    public function markDeclined(?User $recipient = null): self
    {
        $this->status = self::STATUS_DECLINED;
        $this->declinedAt = new \DateTimeImmutable();
        if ($recipient !== null) {
            $this->recipientUser = $recipient;
        }

        return $this;
    }

    public function markRevoked(): self
    {
        $this->status = self::STATUS_REVOKED;
        $this->revokedAt = new \DateTimeImmutable();

        return $this;
    }

    public function markRecipientRemoved(): self
    {
        $this->recipientRemovedAt = new \DateTimeImmutable();

        return $this;
    }

    public function markRestored(): self
    {
        $this->recipientRemovedAt = null;

        return $this;
    }

    /**
     * Turn the relationship into a tombstone: the comic reference is dropped so
     * nothing can serve bytes through it, and the snapshots taken earlier are
     * all that is left to explain the disappearance.
     */
    public function markUnavailable(string $reason): self
    {
        $this->refreshSnapshots();
        $this->comic = null;
        $this->unavailableAt = new \DateTimeImmutable();
        $this->tombstoneReason = $reason;

        return $this;
    }

    /**
     * Strip the owner from a tombstone that outlives their account.
     *
     * Recipients still need to be told why a comic disappeared, but an erased
     * account's name cannot survive in the snapshot that tells them, so the
     * explanation is kept and the person is not.
     */
    public function anonymiseOwner(): self
    {
        $this->owner = null;
        $this->ownerNameSnapshot = 'A former user';

        return $this;
    }

    public function isTombstoned(): bool
    {
        return $this->unavailableAt !== null || $this->comic === null;
    }

    public function isExpired(?\DateTimeImmutable $now = null): bool
    {
        return $this->expiresAt !== null && $this->expiresAt < ($now ?? new \DateTimeImmutable());
    }

    /**
     * Whether this record currently grants the recipient the right to read the
     * comic. Removing it from their own collection deliberately does not: the
     * comic is hidden, not un-shared.
     */
    public function grantsAccess(): bool
    {
        return $this->status === self::STATUS_ACCEPTED
            && $this->comic !== null
            && $this->unavailableAt === null;
    }

    /**
     * Whether the recipient may actually read the comic right now.
     *
     * Access and readability are separated because an explicit comic can suspend
     * the second without touching the first: the relationship survives an age
     * gate closing, so the recipient still has a share to confirm against and
     * still keeps their place in their collection once they do.
     */
    public function grantsReadAccess(): bool
    {
        return $this->grantsAccess() && !$this->requiresAdultConfirmation();
    }

    /** Whether the recipient should see this in their normal collection. */
    public function isVisibleInCollection(): bool
    {
        return $this->grantsReadAccess() && $this->recipientRemovedAt === null;
    }

    public function isPending(?\DateTimeImmutable $now = null): bool
    {
        return $this->status === self::STATUS_PENDING
            && !$this->isTombstoned()
            && !$this->isExpired($now);
    }
}
