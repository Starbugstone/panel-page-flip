<?php

namespace App\Service;

use App\Entity\ComicShare;

/**
 * The shape of a sharing relationship in API responses.
 *
 * Two views of the same record, because the two sides need different things:
 * an owner is managing who has access, a recipient is deciding what to do with
 * a comic somebody else controls.
 *
 * Neither view exposes a token. Invitation links are emailed, and the owner's
 * copy is handed back once, at the moment the invitation is created.
 */
class ComicShareSerializer
{
    public function __construct(private readonly ComicSerializer $comicSerializer)
    {
    }

    /**
     * @param list<ComicShare> $shares
     * @return list<array<string, mixed>>
     */
    public function serializeManyForOwner(array $shares): array
    {
        return array_map(fn (ComicShare $share) => $this->forOwner($share), $shares);
    }

    /**
     * @param list<ComicShare> $shares
     * @return list<array<string, mixed>>
     */
    public function serializeManyForRecipient(array $shares): array
    {
        return array_map(fn (ComicShare $share) => $this->forRecipient($share), $shares);
    }

    /**
     * @return array<string, mixed>
     */
    public function forOwner(ComicShare $share): array
    {
        return $this->common($share) + [
            'recipientEmail' => $share->getRecipientEmailNormalized(),
            'recipientName' => $share->getRecipientUser()?->getName(),
            // Resending only makes sense while the recipient still has a choice
            // to make and there is still a comic behind the invitation.
            'canResend' => !$share->isTombstoned()
                && $share->getStatus() !== ComicShare::STATUS_ACCEPTED,
            'canRevoke' => !$share->isTombstoned()
                && in_array($share->getStatus(), [ComicShare::STATUS_PENDING, ComicShare::STATUS_ACCEPTED], true),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function forRecipient(ComicShare $share): array
    {
        $owner = $share->getOwner();

        return $this->common($share) + [
            'ownerName' => $share->getOwnerNameSnapshot(),
            'ownerId' => $owner?->getId(),
            'removedFromCollection' => $share->getRecipientRemovedAt()?->format('c'),
            // An invitation can still be answered from the Sharing page while it
            // is pending, has a comic behind it and has not run out.
            'canAnswer' => $share->isPending(),
            'canRead' => $share->grantsAccess(),
            'canRestore' => $share->grantsAccess() && $share->getRecipientRemovedAt() !== null,
            'canRemove' => $share->grantsAccess() && $share->getRecipientRemovedAt() === null,
            // A dead entry is one nothing can be done with any more; these are
            // what "Remove all dead shares" clears.
            'isDead' => $share->isTombstoned()
                || in_array($share->getStatus(), [ComicShare::STATUS_REVOKED, ComicShare::STATUS_DECLINED], true),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function common(ComicShare $share): array
    {
        $comic = $share->getComic();

        return [
            'id' => $share->getId(),
            'status' => $share->getStatus(),
            'comicId' => $comic?->getId(),
            // Falls back to the snapshot so a tombstone can still name the
            // comic it used to be.
            'comicTitle' => $comic?->getTitle() ?? $share->getComicTitleSnapshot(),
            'comicAuthor' => $comic?->getAuthor() ?? $share->getComicAuthorSnapshot(),
            'pageCount' => $comic?->getPageCount(),
            // Null for a tombstone: there is no file left, and the URL of one
            // that has been deleted must not be handed out.
            'coverImagePath' => $comic ? $this->comicSerializer->coverUrl($comic) : null,
            'createdAt' => $share->getCreatedAt()->format('c'),
            'acceptedAt' => $share->getAcceptedAt()?->format('c'),
            'declinedAt' => $share->getDeclinedAt()?->format('c'),
            'revokedAt' => $share->getRevokedAt()?->format('c'),
            'expiresAt' => $share->getExpiresAt()?->format('c'),
            'isExpired' => $share->isExpired(),
            'unavailableAt' => $share->getUnavailableAt()?->format('c'),
            'tombstoneReason' => $share->getTombstoneReason(),
            'isTombstoned' => $share->isTombstoned(),
        ];
    }
}
