<?php

namespace App\Service;

use App\Entity\ComicShare;
use App\Enum\ShareCodeType;

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
        // Never redacted. The owner classified the comic, and owns the file; an
        // age gate protects a recipient from content they have not agreed to
        // see, not a person from their own library.
        // The one thing an owner is not always shown. When the sender reached
        // this person through their receiver code, the whole point was that the
        // address never crossed over — so handing it back on the page that
        // lists what they shared would undo the feature entirely. They get the
        // name and the code instead, which is exactly what they were given.
        $hidden = $share->isRecipientAddressHiddenFromOwner();
        $sharingUnavailable = ($share->getComic()?->isSharingRestricted() ?? false)
            || ($share->getComic()?->isQuarantined() ?? false);
        $recipientUser = $share->getRecipientUser();
        // The recipient's handles as they are now, never the ones stored on the
        // share. Those record how the relationship began and go stale the
        // moment the recipient rotates or renames; showing them would offer the
        // owner a retired code and quietly undo the rotation. A recipient whose
        // account has gone keeps the name snapshot and loses the code, rather
        // than falling back to the address the code existed to withhold.
        $currentCode = $hidden ? ($recipientUser?->getUserCode() ?: null) : null;
        $username = $recipientUser?->getUsername() ?: null;
        $currentName = $hidden
            ? ($recipientUser?->getName() ?: $share->getRecipientAliasName())
            : $recipientUser?->getName();

        return $this->common($share, false) + [
            'recipientEmail' => $hidden ? null : $share->getRecipientEmailNormalized(),
            'recipientUsername' => $username,
            // Grouped and prefixed, because that is the only form a code is ever
            // shown in and the sender will be pasting it back into the share
            // dialog.
            'recipientUserCode' => $currentCode === null
                ? null
                : SharingCodeFormat::forDisplay(ShareCodeType::USER, $currentCode),
            // Username first wherever there is one. It is the public identity
            // of a registered account, and unlike the display name it is
            // unique — so it is what an owner should be reading when they
            // decide whether to revoke somebody.
            'recipientLabel' => self::label($username, $currentName, $hidden, $share->getRecipientEmailNormalized()),
            'recipientName' => $currentName,
            // Resending only makes sense while the recipient still has a choice
            // to make and there is still a comic behind the invitation.
            'canResend' => !$share->isTombstoned()
                && !$sharingUnavailable
                && $share->getStatus() !== ComicShare::STATUS_ACCEPTED,
            'canRevoke' => !$share->isTombstoned()
                && in_array($share->getStatus(), [ComicShare::STATUS_PENDING, ComicShare::STATUS_ACCEPTED], true),
            // The share is real whatever this says. It exists so an owner whose
            // mail server was having a bad afternoon is told the notice did not
            // arrive, rather than being left to wonder why nobody answered.
            'notificationState' => $share->getNotificationState(),
            'notifiedAt' => $share->getNotifiedAt()?->format('c'),
        ];
    }

    /**
     * How to name a recipient in one line.
     *
     * A registered account is its username, optionally with the display name in
     * front of it — never the display name alone, which is not unique and so
     * cannot confirm anything. An invitation to somebody with no account yet is
     * the address the sender typed, because that is genuinely all either side
     * knows. And a hidden recipient with no account left to ask keeps whatever
     * name was snapshotted rather than falling back to the address.
     */
    private static function label(?string $username, ?string $name, bool $hidden, string $email): string
    {
        if ($username !== null) {
            $handle = UsernamePolicy::forDisplay($username);

            return $name === null || $name === '' ? $handle : sprintf('%s (%s)', $name, $handle);
        }

        if ($hidden) {
            return $name !== null && $name !== '' ? $name : 'Shared by code';
        }

        return $email;
    }

    /**
     * @return array<string, mixed>
     */
    public function forRecipient(ComicShare $share): array
    {
        $owner = $share->getOwner();
        $needsConfirmation = $share->requiresAdultConfirmation();
        $sharingUnavailable = ($share->getComic()?->isSharingRestricted() ?? false)
            || ($share->getComic()?->isQuarantined() ?? false);

        return $this->common($share, $needsConfirmation) + [
            'ownerName' => $share->getOwnerNameSnapshot(),
            // The owner's public identity as it is now, so a recipient reads
            // the same handle the owner would give them today. Null once the
            // account is gone, where the snapshot above is all that is left.
            'ownerUsername' => $owner?->getUsername() ?: null,
            'ownerLabel' => self::label(
                $owner?->getUsername() ?: null,
                $owner?->getName() ?: $share->getOwnerNameSnapshot(),
                true,
                ''
            ),
            'ownerId' => $owner?->getId(),
            'removedFromCollection' => $share->getRecipientRemovedAt()?->format('c'),
            // An invitation can still be answered from the Sharing page while it
            // is pending, has a comic behind it and has not run out. Answering
            // an explicit one starts with the age gate, so the page has to be
            // able to tell "you may answer this" from "you may accept it now".
            'canAnswer' => !$sharingUnavailable && $share->isPending(),
            'canRead' => !$sharingUnavailable && $share->grantsReadAccess(),
            'canRestore' => $share->grantsAccess() && $share->getRecipientRemovedAt() !== null,
            'canRemove' => $share->grantsAccess() && $share->getRecipientRemovedAt() === null,
            // A dead entry is one nothing can be done with any more; these are
            // what "Remove all dead shares" clears.
            'isDead' => $share->isTombstoned()
                || in_array($share->getStatus(), [ComicShare::STATUS_REVOKED, ComicShare::STATUS_DECLINED], true),
        ];
    }

    /**
     * @param bool $redact withhold everything that identifies the comic, for a
     *                     recipient who has not passed the age gate. The comic
     *                     id goes too: it is the key to every endpoint that
     *                     serves a cover, a page or an archive, so leaving it in
     *                     would make the gate a suggestion.
     * @return array<string, mixed>
     */
    private function common(ComicShare $share, bool $redact): array
    {
        $comic = $share->getComic();

        return [
            'id' => $share->getId(),
            'status' => $share->getStatus(),
            'comicId' => $redact ? null : $comic?->getId(),
            // Falls back to the snapshot so a tombstone can still name the
            // comic it used to be.
            'comicTitle' => $redact ? null : ($comic?->getTitle() ?? $share->getComicTitleSnapshot()),
            'comicAuthor' => $redact ? null : ($comic?->getAuthor() ?? $share->getComicAuthorSnapshot()),
            'pageCount' => $redact ? null : $comic?->getPageCount(),
            // Null for a tombstone: there is no file left, and the URL of one
            // that has been deleted must not be handed out.
            'coverImagePath' => $comic && !$redact ? $this->comicSerializer->coverUrl($comic) : null,
            // Booleans rather than the stored timestamps: the client only ever
            // needs to know which screen to show. The timestamps stay on the
            // record as the audit trail they were added for.
            'explicitContent' => $share->isExplicitContent(),
            'requiresAdultConfirmation' => $share->requiresAdultConfirmation(),
            'adultConfirmed' => $share->getAdultConfirmedAt() !== null,
            'createdAt' => $share->getCreatedAt()->format('c'),
            'acceptedAt' => $share->getAcceptedAt()?->format('c'),
            'declinedAt' => $share->getDeclinedAt()?->format('c'),
            'revokedAt' => $share->getRevokedAt()?->format('c'),
            'expiresAt' => $share->getExpiresAt()?->format('c'),
            'isExpired' => $share->isExpired(),
            'unavailableAt' => $share->getUnavailableAt()?->format('c'),
            'tombstoneReason' => $share->getTombstoneReason(),
            'isTombstoned' => $share->isTombstoned(),
            'sharingRestricted' => $comic?->isSharingRestricted() ?? false,
            'contentQuarantined' => $comic?->isQuarantined() ?? false,
        ];
    }
}
