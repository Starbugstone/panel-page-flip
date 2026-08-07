<?php

namespace App\Service;

use App\Entity\ComicShare;

/**
 * An invitation that has just been created, together with the one link that
 * opens it.
 *
 * The link is returned once, here, because only its hash is stored: after this
 * response nothing in the system can reconstruct it, which is what makes a
 * database leak useless. An owner who needs another link resends the
 * invitation, and that mints a new one.
 */
final readonly class IssuedInvitation
{
    public function __construct(
        public ComicShare $share,
        public string $invitationUrl,
    ) {
    }
}
