<?php

namespace App\Service;

/**
 * A recipient the sender reached by code rather than by address.
 *
 * Carries only what the recipient published when they handed their code out:
 * the code itself and the name shown beside it. The address the invitation is
 * actually addressed to travels separately and never reaches the sender.
 */
// Individually readonly rather than a readonly class, for the 8.1 floor
// composer declares. Same reasoning as {@see IssuedInvitation}.
final class SharingCodeRecipient
{
    public function __construct(
        public readonly string $sharingCode,
        public readonly ?string $name,
    ) {
    }
}
