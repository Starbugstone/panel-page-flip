<?php

namespace App\Service;

use App\Entity\User;

/**
 * A recipient the sender reached by code rather than by address.
 *
 * Carries the account itself, so the share can record *who* it is for and not
 * only what they were called at the time. That matters because a receiver code
 * can be rotated: the code stored on a share is a note about how the
 * relationship began, and anything that needs the recipient's current handle
 * has to go through the account to find it.
 *
 * The address the invitation is actually addressed to travels separately and
 * never reaches the sender.
 */
// Individually readonly rather than a readonly class, for the 8.1 floor
// composer declares. Same reasoning as {@see IssuedInvitation}.
final class SharingCodeRecipient
{
    public function __construct(
        public readonly User $user,
        public readonly string $sharingCode,
        public readonly ?string $name,
    ) {
    }
}
