<?php

namespace App\Service;

use App\Entity\Comic;
use App\Entity\ComicShare;

/**
 * One invitation that has been built but not yet committed or announced.
 *
 * Bulk sharing needs the relationships to exist before the single email that
 * describes all of them can be rendered, and it needs every one of them to be
 * undone together if that send fails. This carries the three things the send and
 * the rollback both need, so the preparation step can hand them over without the
 * caller reaching back into the unit of work.
 */
// Individually readonly properties rather than a readonly class: composer
// declares "php": ">=8.1" and a readonly *class* is 8.2 syntax that would fatal
// at parse time on 8.1. Same reasoning as {@see IssuedInvitation}.
final class PreparedInvitation
{
    public function __construct(
        public readonly ComicShare $share,
        public readonly Comic $comic,
        /** Exists only until the email is rendered; only its hash is stored. */
        public readonly string $plaintextToken,
    ) {
    }
}
