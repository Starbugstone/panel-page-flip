<?php

namespace App\Entity;

use App\Repository\ShareClaimCodeRedemptionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One account's claim on one code.
 *
 * A counter alone cannot say what "ten uses" means. Decrementing on every
 * request lets one recipient submit the same code ten times and exhaust an
 * offer advertised to ten people — and leaves the owner's "claimed 10 of 10"
 * counting requests rather than people. This row is the difference: a use is
 * spent by an account that did not have one, and never twice by the same one.
 *
 * The unique index is what makes that hold under concurrency. Two simultaneous
 * redemptions by the same account cannot both insert, so the second is turned
 * into the idempotent answer rather than a second decrement.
 *
 * Deliberately not exposed to the owner. They are told how many people took the
 * offer up, which is what they asked; who those people are is between them and
 * the recipients, and the recipient never agreed to be listed.
 */
#[ORM\Entity(repositoryClass: ShareClaimCodeRedemptionRepository::class)]
#[ORM\Table(name: 'share_claim_code_redemption')]
#[ORM\UniqueConstraint(name: 'uniq_claim_code_recipient', columns: ['claim_code_id', 'recipient_id'])]
class ShareClaimCodeRedemption
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: ShareClaimCode::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?ShareClaimCode $claimCode = null;

    /**
     * Cascades with the account. A deleted user takes their redemption record
     * with them, and the use it spent is not handed back — the offer was made
     * and taken, and re-opening it later would surprise the owner.
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $recipient = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $redeemedAt;

    public function __construct(ShareClaimCode $claimCode, User $recipient)
    {
        $this->claimCode = $claimCode;
        $this->recipient = $recipient;
        $this->redeemedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getClaimCode(): ?ShareClaimCode
    {
        return $this->claimCode;
    }

    public function getRecipient(): ?User
    {
        return $this->recipient;
    }

    public function getRedeemedAt(): \DateTimeImmutable
    {
        return $this->redeemedAt;
    }
}
