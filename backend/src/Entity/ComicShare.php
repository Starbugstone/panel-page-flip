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
#[ORM\Index(name: 'IDX_comic_share_invitation_batch', columns: ['invitation_batch_id'])]
class ComicShare
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_ACCEPTED = 'accepted';
    public const STATUS_DECLINED = 'declined';
    public const STATUS_REVOKED = 'revoked';

    /**
     * How the notice about this share is getting on.
     *
     * The relationship is the thing that is true; the email is an announcement
     * of it, and SMTP is not a participant in a database transaction. Recording
     * the announcement separately is what lets a share exist and be usable
     * while its notice is still queued or has failed outright — and lets the
     * owner be told so, and offered a resend, instead of the share being rolled
     * back because a mail server was busy.
     */
    public const NOTIFICATION_PENDING = 'pending';
    public const NOTIFICATION_SENT = 'sent';
    public const NOTIFICATION_FAILED = 'failed';

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
     * Set when the owner reached this recipient without ever seeing their
     * address — by username, by `U-` code, or because the recipient redeemed a
     * content code the owner put into the world.
     *
     * The address the owner never learned must not be handed back to them by
     * the page that lists what they shared. These two carry what they are shown
     * instead: the recipient's name as it was, and the code that can be used to
     * offer them something else.
     *
     * Both null for an ordinary email invitation, where the sender typed the
     * address themselves and there is nothing to withhold.
     */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $recipientAliasName = null;

    #[ORM\Column(length: 16, nullable: true)]
    private ?string $recipientUserCode = null;

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

    /**
     * Defaults to `sent` for the rows that predate this column: they were
     * created under the previous rule, where a share only came into existence
     * if its email had already gone out.
     */
    #[ORM\Column(length: 16, options: ['default' => self::NOTIFICATION_SENT])]
    private string $notificationState = self::NOTIFICATION_SENT;

    /**
     * One decision shared by every comic offered from a folder in one action.
     *
     * The grants remain per comic so the owner can withdraw one later. This id
     * only joins their pending invitation lifecycle: one email link, one age
     * confirmation and one accept or decline. Null for hand-picked shares and
     * for every invitation created before folder batches existed.
     */
    #[ORM\Column(length: 32, nullable: true)]
    private ?string $invitationBatchId = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $invitationBatchName = null;

    #[ORM\Column(nullable: true)]
    private ?int $invitationBatchSize = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $notifiedAt = null;

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
     * Record that the owner never saw this recipient's address.
     *
     * Called with the recipient's own name and `U-` code, both of which they
     * publish by having an account other people can share with. Nothing else
     * about them crosses over.
     */
    public function hideRecipientBehindSharingCode(string $userCode, ?string $recipientName): self
    {
        $this->recipientUserCode = $userCode;
        $this->recipientAliasName = $recipientName;

        return $this;
    }

    /**
     * Attach the account this share is for, before they have answered.
     *
     * Normally the link is made on acceptance, because an invitation may be
     * addressed to somebody who has no account yet. A share made through a
     * receiver code is the exception: the code *is* an account, so the
     * relationship knows who it is for from the start.
     *
     * That link is what survives a rotation. `recipientUserCode` records how
     * this relationship began and goes stale the moment the recipient replaces
     * their code; anything that needs their current handle asks the account.
     */
    public function linkRecipientUser(User $recipient): self
    {
        $this->recipientUser = $recipient;

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
        $this->recipientUserCode = null;
        $this->recipientAliasName = null;

        return $this;
    }

    /** Whether the owner may be shown this recipient's address. */
    public function isRecipientAddressHiddenFromOwner(): bool
    {
        return $this->recipientUserCode !== null;
    }

    public function getRecipientAliasName(): ?string
    {
        return $this->recipientAliasName;
    }

    public function getRecipientUserCode(): ?string
    {
        return $this->recipientUserCode;
    }

    public function getNotificationState(): string
    {
        return $this->notificationState;
    }

    public function getNotifiedAt(): ?\DateTimeImmutable
    {
        return $this->notifiedAt;
    }

    /** The notice is queued and has not been attempted yet. */
    public function awaitNotification(): self
    {
        $this->notificationState = self::NOTIFICATION_PENDING;
        $this->notifiedAt = null;

        return $this;
    }

    public function markNotified(): self
    {
        $this->notificationState = self::NOTIFICATION_SENT;
        $this->notifiedAt = new \DateTimeImmutable();

        return $this;
    }

    /**
     * The notice could not be delivered.
     *
     * Recorded rather than thrown away, and deliberately not fatal: the share
     * is real and the recipient can still find it on their Sharing page. What
     * the owner needs is to know that the email did not arrive, so they can say
     * so or press resend.
     */
    public function markNotificationFailed(): self
    {
        $this->notificationState = self::NOTIFICATION_FAILED;

        return $this;
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

    /**
     * Carry an acknowledgement the owner already made onto this share.
     *
     * A share created by redeeming a claim code is not a moment the owner was
     * present for: they acknowledged responsibility when they created the code,
     * possibly hours earlier. Stamping "now" would put a timestamp in the
     * canonical audit field for an act the audited party did not perform then —
     * and an audit trail that records the wrong moment is worse than one that
     * records nothing.
     *
     * Takes the timestamp rather than generating one, and is deliberately
     * separate from {@see acceptSenderResponsibility()} so nothing can pass a
     * request-supplied value in by accident: the only caller hands it the
     * server-generated timestamp already stored on the claim code.
     */
    public function inheritSenderResponsibility(\DateTimeImmutable $acknowledgedAt): self
    {
        $this->senderResponsibilityAcceptedAt = $acknowledgedAt;

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

    public function joinInvitationBatch(string $batchId, string $folderName, int $size): self
    {
        $this->invitationBatchId = $batchId;
        $this->invitationBatchName = $folderName;
        $this->invitationBatchSize = $size;

        return $this;
    }

    public function leaveInvitationBatch(): self
    {
        $this->invitationBatchId = null;
        $this->invitationBatchName = null;
        $this->invitationBatchSize = null;

        return $this;
    }

    public function getInvitationBatchId(): ?string
    {
        return $this->invitationBatchId;
    }

    public function getInvitationBatchName(): ?string
    {
        return $this->invitationBatchName;
    }

    public function getInvitationBatchSize(): ?int
    {
        return $this->invitationBatchSize;
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
