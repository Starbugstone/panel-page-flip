<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\UserWarningRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Something an administrator needs one account to read before carrying on.
 *
 * The gap it fills is between an audit entry and an enforcement action. An
 * administrator who finds a mis-tagged comic or a share that should not have
 * been made has, until now, only had two moves: do nothing, or take the content
 * away. Neither of those tells anybody what was wrong, so the same thing
 * happens again — and the account is left guessing why their library changed.
 *
 * So this is a message, and deliberately only a message:
 *
 * - **It blocks nothing.** Reading, uploading and sharing are untouched.
 *   Restricting an account is a separate decision on {@see ContentReport}, and
 *   conflating the two would make every warning feel like a punishment already
 *   applied
 * - **It is acknowledged, not deleted.** Dismissing it clears it from the
 *   recipient's screen and keeps the record, so "were they told?" has an answer
 *   later
 * - **It carries its own context.** The comic or share it is about is
 *   referenced *and* described, because the usual reason to warn somebody about
 *   a comic is that the comic is about to stop existing
 */
#[ORM\Entity(repositoryClass: UserWarningRepository::class)]
#[ORM\Table(name: 'user_warning')]
#[ORM\Index(name: 'idx_user_warning_recipient_open', columns: ['recipient_id', 'acknowledged_at'])]
class UserWarning
{
    /**
     * What the warning is about, which decides how it is introduced.
     *
     * Not derived from whether `comic` or `share` is set: those are references
     * that go null when the thing they point at is deleted, and a warning about
     * a comic does not stop being about a comic when the comic is removed —
     * that is usually the whole point of it.
     */
    public const SUBJECT_ACCOUNT = 'account';
    public const SUBJECT_COMIC = 'comic';
    public const SUBJECT_SHARE = 'share';

    public const SUBJECTS = [self::SUBJECT_ACCOUNT, self::SUBJECT_COMIC, self::SUBJECT_SHARE];

    /**
     * Long enough for an administrator to explain themselves properly, short
     * enough that the column is a column. Enforced before the entity is built.
     */
    public const MAX_MESSAGE_LENGTH = 2000;

    /** How the emailed copy got on, when one was asked for. */
    public const EMAIL_NOT_REQUESTED = 'not_requested';
    public const EMAIL_SENT = 'sent';
    public const EMAIL_FAILED = 'failed';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $recipient;

    /**
     * Who sent it. Nullable because an administrator's account can be deleted,
     * and losing them must not take the warning with it — the recipient was
     * still told, and the record of that is the point.
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $issuedBy = null;

    #[ORM\Column(type: Types::TEXT)]
    private string $message;

    #[ORM\Column(length: 16)]
    private string $subject;

    #[ORM\ManyToOne(targetEntity: Comic::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Comic $comic = null;

    #[ORM\ManyToOne(targetEntity: ComicShare::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?ComicShare $share = null;

    /**
     * What the warning was about, in words, written down at the time.
     *
     * The references above are the live link and this is the durable one. A
     * warning about a comic that is then deleted would otherwise read as a
     * complaint about nothing in particular, which is precisely the case where
     * the recipient most needs to know which comic was meant.
     */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $subjectLabel = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $acknowledgedAt = null;

    #[ORM\Column(length: 16, options: ['default' => self::EMAIL_NOT_REQUESTED])]
    private string $emailState = self::EMAIL_NOT_REQUESTED;

    public function __construct(
        User $recipient,
        ?User $issuedBy,
        string $message,
        string $subject = self::SUBJECT_ACCOUNT,
        ?string $subjectLabel = null,
    ) {
        $this->recipient = $recipient;
        $this->issuedBy = $issuedBy;
        $this->message = $message;
        $this->subject = in_array($subject, self::SUBJECTS, true) ? $subject : self::SUBJECT_ACCOUNT;
        $this->subjectLabel = $subjectLabel;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getRecipient(): User
    {
        return $this->recipient;
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    public function getSubject(): string
    {
        return $this->subject;
    }

    public function getSubjectLabel(): ?string
    {
        return $this->subjectLabel;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function isAcknowledged(): bool
    {
        return $this->acknowledgedAt !== null;
    }

    public function getAcknowledgedAt(): ?\DateTimeImmutable
    {
        return $this->acknowledgedAt;
    }

    public function about(?Comic $comic, ?ComicShare $share): self
    {
        $this->comic = $comic;
        $this->share = $share;

        return $this;
    }

    public function getComic(): ?Comic
    {
        return $this->comic;
    }

    /** Idempotent: dismissing twice is one dismissal, and keeps the first time. */
    public function acknowledge(): self
    {
        $this->acknowledgedAt ??= new \DateTimeImmutable();

        return $this;
    }

    public function getEmailState(): string
    {
        return $this->emailState;
    }

    public function recordEmailSent(): self
    {
        $this->emailState = self::EMAIL_SENT;

        return $this;
    }

    /**
     * The copy did not go out. Recorded rather than thrown, because the warning
     * itself is real either way — it is waiting on the recipient's next visit,
     * which is the delivery that matters.
     */
    public function recordEmailFailed(): self
    {
        $this->emailState = self::EMAIL_FAILED;

        return $this;
    }

    /**
     * What the warned account is shown.
     *
     * Never the administrator's identity. A warning is from the operator of the
     * service, not from a person the recipient can go and argue with, and
     * naming an individual invites exactly that.
     *
     * @return array<string, mixed>
     */
    public function toRecipientPayload(): array
    {
        return [
            'id' => $this->id,
            'message' => $this->message,
            'subject' => $this->subject,
            'subjectLabel' => $this->subjectLabel,
            'createdAt' => $this->createdAt->format('c'),
        ];
    }

    /**
     * What an administrator is shown about a warning already sent.
     *
     * @return array<string, mixed>
     */
    public function toAdminPayload(): array
    {
        return [
            'id' => $this->id,
            'message' => $this->message,
            'subject' => $this->subject,
            'subjectLabel' => $this->subjectLabel,
            'createdAt' => $this->createdAt->format('c'),
            'acknowledgedAt' => $this->acknowledgedAt?->format('c'),
            'emailState' => $this->emailState,
            'recipient' => [
                'id' => $this->recipient->getId(),
                'name' => $this->recipient->getName(),
                'email' => $this->recipient->getEmail(),
            ],
            'issuedBy' => $this->issuedBy === null ? null : [
                'id' => $this->issuedBy->getId(),
                'name' => $this->issuedBy->getName(),
            ],
        ];
    }
}
