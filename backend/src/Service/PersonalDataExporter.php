<?php

namespace App\Service;

use App\Entity\Comic;
use App\Entity\ComicShare;
use App\Entity\User;
use App\Entity\UserWarning;
use App\Repository\ComicShareRepository;
use App\Repository\UserWarningRepository;

final class PersonalDataExporter
{
    public function __construct(
        private readonly ComicSerializer $comicSerializer,
        private readonly ComicShareRepository $shareRepository,
        private readonly UserWarningRepository $warningRepository,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function export(User $user): array
    {
        $comics = [];
        foreach ($user->getComics() as $comic) {
            $comics[] = $this->mapComic($comic);
        }

        $progress = [];
        foreach ($user->getReadingProgress() as $item) {
            $progress[] = [
                'comicId' => $item->getComic()?->getId(),
                'comicTitle' => $item->getComic()?->getTitle(),
                'currentPage' => $item->getCurrentPage(),
                'completed' => $item->isCompleted(),
                'lastReadAt' => $item->getLastReadAt()?->format(\DateTimeInterface::ATOM),
            ];
        }

        $tags = [];
        foreach ($user->getCreatedTags() as $tag) {
            $tags[] = [
                'id' => $tag->getId(),
                'name' => $tag->getName(),
                'hideFromLibrary' => $tag->hidesFromLibrary(),
                'createdAt' => $tag->getCreatedAt()?->format(\DateTimeInterface::ATOM),
            ];
        }

        // Tombstones included here, unlike on the sharing page: an export is a
        // record of what is stored about the user, not a management view.
        $sentShares = $this->mapShares($this->shareRepository->findAllForOwnerIncludingTombstones($user));
        $receivedShares = $this->mapShares($this->shareRepository->findAllForRecipient($user));

        // Dismissed ones included, and never the administrator who sent them.
        // A notice is stored about this user and so belongs in their export;
        // who wrote it is the operator's record, not a fact about the subject.
        $warnings = array_map(
            static fn (UserWarning $warning): array => [
                'id' => $warning->getId(),
                'message' => $warning->getMessage(),
                'subject' => $warning->getSubject(),
                'subjectLabel' => $warning->getSubjectLabel(),
                'createdAt' => $warning->getCreatedAt()->format(\DateTimeInterface::ATOM),
                'dismissedAt' => $warning->getAcknowledgedAt()?->format(\DateTimeInterface::ATOM),
            ],
            $this->warningRepository->findBy(['recipient' => $user], ['createdAt' => 'ASC'])
        );

        return [
            'exportedAt' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'account' => [
                'id' => $user->getId(),
                'email' => $user->getEmail(),
                'name' => $user->getName(),
                'roles' => $user->getRoles(),
                'emailVerified' => $user->isEmailVerified(),
                'createdAt' => $user->getCreatedAt()?->format(\DateTimeInterface::ATOM),
                'updatedAt' => $user->getUpdatedAt()?->format(\DateTimeInterface::ATOM),
                'lastLoginAt' => $user->getLastLoginAt()?->format(\DateTimeInterface::ATOM),
                'dropboxConnected' => $user->getDropboxAccessToken() !== null
                    || $user->getDropboxRefreshToken() !== null,
                'dropboxLastSyncedAt' => $user->getDropboxLastSyncedAt()?->format(\DateTimeInterface::ATOM),
                'readerPreferences' => $user->getReaderPreferences(),
            ],
            'comics' => $comics,
            'readingProgress' => $progress,
            'personalTags' => $tags,
            'sharesGranted' => $sentShares,
            'sharesReceived' => $receivedShares,
            'administratorNotices' => $warnings,
        ];
    }

    /**
     * The comic as the owner supplied it. Deliberately not ComicSerializer's
     * API shape: an export describes what the user stored, so it carries no
     * reading progress (exported separately) and no internal storage path.
     *
     * @return array<string, mixed>
     */
    private function mapComic(Comic $comic): array
    {
        return [
            'id' => $comic->getId(),
            'title' => $comic->getTitle(),
            'author' => $comic->getAuthor(),
            'publisher' => $comic->getPublisher(),
            'description' => $comic->getDescription(),
            // The owner's own classification, and stored as such. An export
            // that named it only on the shares handed out would omit it
            // entirely for a comic nobody has been invited to.
            'explicitContent' => $comic->isExplicitContent(),
            'coverImagePath' => $this->comicSerializer->coverUrl($comic),
            'pageCount' => $comic->getPageCount(),
            'fileSize' => $comic->getFileSize(),
            'uploadedAt' => $comic->getUploadedAt()?->format(\DateTimeInterface::ATOM),
            'updatedAt' => $comic->getUpdatedAt()?->format(\DateTimeInterface::ATOM),
            'tags' => array_map(
                static fn ($tag): ?string => $tag->getName(),
                $comic->getTags()->toArray(),
            ),
        ];
    }

    /**
     * The comic reference falls back to the snapshot so a tombstoned share is
     * still recognisable in an export.
     *
     * @param list<ComicShare> $shares
     * @return list<array<string, mixed>>
     */
    private function mapShares(array $shares): array
    {
        return array_map(
            static fn (ComicShare $share): array => [
                'comicId' => $share->getComic()?->getId(),
                'comicTitle' => $share->getComic()?->getTitle() ?? $share->getComicTitleSnapshot(),
                'ownerUserId' => $share->getOwner()?->getId(),
                'ownerName' => $share->getOwnerNameSnapshot(),
                'recipientEmail' => $share->getRecipientEmailNormalized(),
                'status' => $share->getStatus(),
                'createdAt' => $share->getCreatedAt()->format(\DateTimeInterface::ATOM),
                'acceptedAt' => $share->getAcceptedAt()?->format(\DateTimeInterface::ATOM),
                'declinedAt' => $share->getDeclinedAt()?->format(\DateTimeInterface::ATOM),
                'revokedAt' => $share->getRevokedAt()?->format(\DateTimeInterface::ATOM),
                'expiresAt' => $share->getExpiresAt()?->format(\DateTimeInterface::ATOM),
                'unavailableAt' => $share->getUnavailableAt()?->format(\DateTimeInterface::ATOM),
                'tombstoneReason' => $share->getTombstoneReason(),
                // The two declarations this share records. They are statements
                // the user made about themselves, so an export of what is held
                // about them has to include them.
                'senderResponsibilityAcceptedAt' => $share->getSenderResponsibilityAcceptedAt()?->format(\DateTimeInterface::ATOM),
                'adultConfirmedAt' => $share->getAdultConfirmedAt()?->format(\DateTimeInterface::ATOM),
                'explicitContent' => $share->isExplicitContent(),
            ],
            $shares,
        );
    }
}
