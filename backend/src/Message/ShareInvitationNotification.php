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
     * @param list<int> $shareIds       the relationships this one notice covers; a
     *                                  bulk share sends one email, not one per comic
     * @param int|null  $sourceFolderId the folder the sender pointed at, when they
     *                                  shared one. An id rather than a name for the
     *                                  same reason as everything else here: the
     *                                  worker reads the current name at send time,
     *                                  and a folder renamed or deleted in between
     *                                  cannot leave a stale one in an email. Null
     *                                  for every share that did not come from a
     *                                  folder, including messages queued before
     *                                  folder sharing existed.
     */
    public function __construct(
        public readonly int $ownerId,
        public readonly array $shareIds,
        public readonly ?int $sourceFolderId = null,
    ) {
    }

    /**
     * Messenger's default serializer is native PHP serialize. A payload queued
     * before folder sharing existed has no sourceFolderId, and a missing
     * readonly property stays uninitialized — folderName() would then throw on
     * a perfectly ordinary invitation. Default it here the same way the
     * constructor does.
     *
     * @param array<string, mixed> $data
     */
    public function __unserialize(array $data): void
    {
        $this->ownerId = (int) $data['ownerId'];
        $this->shareIds = array_values(array_map(
            static fn (mixed $id): int => (int) $id,
            is_array($data['shareIds'] ?? null) ? $data['shareIds'] : []
        ));
        $this->sourceFolderId = array_key_exists('sourceFolderId', $data) && $data['sourceFolderId'] !== null
            ? (int) $data['sourceFolderId']
            : null;
    }
}
