<?php

namespace App\Service;

use App\Entity\ComicShare;

/**
 * An invitation that has just been created, together with the one link that
 * opens it.
 *
 * The link exists in this internal result because only its hash is stored. It
 * is sent to the recipient and is never serialized by a sharing API. Resending
 * mints a replacement link for another email.
 */
// Properties are marked readonly individually rather than the class as a whole:
// composer declares "php": ">=8.1" and docker-compose lets PHP_VERSION be
// overridden, and a readonly *class* is 8.2 syntax that would fatal at parse
// time on 8.1. One DTO is not a reason to narrow what the project runs on.
final class IssuedInvitation
{
    public function __construct(
        public readonly ComicShare $share,
        public readonly string $invitationUrl,
    ) {
    }
}
