<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Tell somebody about shares that already exist.
 *
 * Ids only, and that is the whole design. The obvious alternative — build the
 * email while the shares are being created and put *that* on the queue — puts
 * plaintext bearer invitation tokens into the message store, where they sit in
 * a database table, get retried, and end up in a failure transport that an
 * operator reads. A queue row is not a place to keep a capability.
 *
 * So the message carries nothing that grants anything. The worker reloads the
 * relationships, mints the links at the moment it is about to send them, and
 * renders the email then — which also means a notice retried an hour later
 * carries a link that works rather than one that expired while it waited.
 */
final class ShareInvitationNotification
{
    /**
     * @param list<int> $shareIds the relationships this one notice covers; a
     *                            bulk share sends one email, not one per comic
     */
    public function __construct(
        public readonly int $ownerId,
        public readonly array $shareIds,
    ) {
    }
}
