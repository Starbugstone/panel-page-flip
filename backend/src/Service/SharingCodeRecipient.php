<?php

namespace App\Service;

use App\Entity\User;

/**
 * A recipient the sender reached without ever seeing their address.
 *
 * By `U-` code or by exact username — the two ways of naming somebody that go
 * through their public identity rather than their private one. Both produce the
 * same share and the same obligation: the address stays withheld from the owner
 * afterwards, on the Sharing page as much as at the moment of sending.
 *
 * Carries the account itself, so the share can record *who* it is for and not
 * only what they were called at the time. That matters because both handles can
 * change: what is stored on a share is a note about how the relationship began,
 * and anything that needs the recipient's current handle goes through the
 * account to find it.
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
        public readonly string $userCode,
        public readonly ?string $name,
    ) {
    }

    /** The recipient as the account describes itself right now. */
    public static function forUser(User $user): self
    {
        return new self($user, $user->getUserCode(), $user->getName());
    }
}
