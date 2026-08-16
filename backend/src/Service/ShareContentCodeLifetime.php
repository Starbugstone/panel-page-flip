<?php

declare(strict_types=1);

namespace App\Service;

/**
 * How long a `C-` or `G-` code stays live, as the operator configured it.
 *
 * One setting for both, because they are the same capability with different
 * cargo, and an installation that wanted comic codes to outlive group codes
 * would be describing a distinction its users have no way to see.
 *
 * The lifetime is applied when a code is minted and written onto the row as an
 * absolute moment. Changing the setting therefore governs codes issued from
 * then on and never reaches back: an owner who told somebody "this works until
 * Friday" must not find that an operator moved Friday.
 *
 * User codes are not in scope here. A `U-` code is an address rather than a
 * capability — it grants nothing, so there is nothing for an expiry to contain,
 * and it lives until its owner rotates it.
 */
final class ShareContentCodeLifetime
{
    /**
     * A week, which is the value shipped in `.env`.
     *
     * Long enough to survive somebody being away for the weekend, short enough
     * that a code posted into a group chat is not still working next month.
     */
    public const DEFAULT_DAYS = 7;

    /**
     * A ceiling on what an operator may configure.
     *
     * Not a security boundary — the operator owns the installation — but a
     * misconfigured `SHARE_CONTENT_CODE_TTL_DAYS=36500` produces codes that are
     * effectively permanent, and a typo should not be able to do that silently.
     */
    public const MAX_DAYS = 365;

    private readonly int $days;

    public function __construct(int $contentCodeTtlDays = self::DEFAULT_DAYS)
    {
        if ($contentCodeTtlDays < 1 || $contentCodeTtlDays > self::MAX_DAYS) {
            // Thrown at construction, so a deployment with a nonsense value
            // fails on the way up rather than quietly minting codes that expire
            // in the past. A container built from bad configuration is a
            // deployment problem, and the useful moment to say so is now.
            throw new \InvalidArgumentException(sprintf(
                'SHARE_CONTENT_CODE_TTL_DAYS must be a whole number of days between 1 and %d, got %d.',
                self::MAX_DAYS,
                $contentCodeTtlDays
            ));
        }

        $this->days = $contentCodeTtlDays;
    }

    public function days(): int
    {
        return $this->days;
    }

    /** When a code minted now would stop working. */
    public function expiryFrom(?\DateTimeImmutable $createdAt = null): \DateTimeImmutable
    {
        return ($createdAt ?? new \DateTimeImmutable())->modify(sprintf('+%d days', $this->days));
    }
}
