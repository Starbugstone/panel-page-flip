<?php

declare(strict_types=1);

namespace App\Enum;

use App\Entity\ContentReport;
use App\Service\PublicUrl;
use App\Service\SharingCodeFormat;

/**
 * How a reporter says they can identify the material they are reporting.
 *
 * Each kind behaves differently in three places: what counts as a valid
 * reference, how much of it may be echoed back in the reporter's receipt, and
 * whether the free text is worth searching the library for. Those rules used to
 * live as parallel `if` chains in three unrelated services, and a new kind that
 * missed one failed silently in a different way each time — anything accepted,
 * a report filed permanently unlinked, or a capability-bearing token echoed
 * unmasked into outbound mail.
 *
 * Gathered here so a `match` over the cases is exhaustive: PHPStan fails the
 * build for an unhandled one rather than letting it fall through a `default`.
 */
enum ReportedReferenceType: string
{
    case InvitationUrl = 'invitation_url';
    case SharingCode = 'sharing_code';
    case UserCode = 'user_code';
    case Account = 'account_reference';
    case Comic = 'comic_reference';
    case PanelUrl = 'panel_url';
    case Other = 'other';

    /** Why the reference is unusable, or null when it is fine. */
    public function validate(string $reference, PublicUrl $publicUrl): ?string
    {
        return match ($this) {
            self::InvitationUrl => $publicUrl->matchPath($reference, ContentReport::PATH_INVITATION_URL) !== null
                ? null : 'Provide a valid HTTP(S) Panel Page Flip invitation URL.',
            self::PanelUrl => $publicUrl->matchPath($reference, ContentReport::PATH_PANEL_URL) !== null
                ? null : 'Provide a valid HTTP(S) Panel Page Flip reading URL.',
            self::SharingCode => SharingCodeFormat::parse($reference)?->type->isContentCode() === true
                ? null : 'Provide a valid C- comic code or G- group code.',
            self::UserCode => SharingCodeFormat::parse($reference)?->type === ShareCodeType::USER
                ? null : 'Provide a valid U- user code.',
            // Free text. There is nothing to check beyond the length limits the
            // caller already applied, and refusing what somebody typed in good
            // faith is worse than filing a report an admin has to read.
            self::Account, self::Comic, self::Other => null,
        };
    }

    /**
     * The reference as it may appear in the acknowledgement sent to the reporter.
     *
     * Invitation links and sharing codes are capabilities: whoever holds one can
     * use it. The reporter has already seen the value, but the receipt travels
     * by email, so what goes back is enough to recognise and not enough to use.
     */
    public function maskForReceipt(string $reference): string
    {
        return match ($this) {
            self::InvitationUrl => preg_replace('~(/share/invitation/)[^/?#]+~', '$1[masked-token]', $reference)
                ?? '[masked invitation link]',
            self::SharingCode, self::UserCode => preg_replace('/^([CGU])-.+$/i', '$1-[masked-code]', $reference)
                ?? '[masked sharing code]',
            self::Account, self::Comic, self::PanelUrl, self::Other => $reference,
        };
    }

    /** Whether a report of this kind has to name the work it is about. */
    public function requiresContentTitle(): bool
    {
        return $this === self::Comic;
    }

    /**
     * Whether the reference itself is free text worth searching the library for.
     *
     * An application-issued URL or code resolves exactly or not at all; putting
     * one through a `LIKE '%…%'` scan finds nothing and costs a full table read.
     */
    public function isSearchableText(): bool
    {
        return $this === self::Comic || $this === self::Account;
    }
}
