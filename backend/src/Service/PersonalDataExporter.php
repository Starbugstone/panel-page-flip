<?php

namespace App\Service;

use App\Entity\Comic;
use App\Entity\ComicShare;
use App\Entity\LibraryFolder;
use App\Entity\LibraryFolderItem;
use App\Entity\User;
use App\Entity\UserOAuthIdentity;
use App\Entity\UserWarning;
use App\Repository\ComicShareRepository;
use App\Repository\LibraryFolderItemRepository;
use App\Repository\LibraryFolderRepository;
use App\Repository\UserWarningRepository;

final class PersonalDataExporter
{
    public function __construct(
        private readonly ComicSerializer $comicSerializer,
        private readonly ComicShareRepository $shareRepository,
        private readonly ComicShareSerializer $shareSerializer,
        private readonly UserWarningRepository $warningRepository,
        private readonly LibraryFolderRepository $folderRepository,
        private readonly LibraryFolderItemRepository $folderItemRepository,
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

        // Tombstones included here, unlike on the sharing page: an export is a
        // record of what is stored about the user, not a management view.
        $sentShares = $this->shareRepository->findAllForOwnerIncludingTombstones($user);
        $receivedShares = $this->shareRepository->findAllForRecipient($user);

        // Comics this account has not passed the age gate for. Reading progress
        // or a folder placement can outlive a comic being marked explicit, and
        // neither may name what the gate withholds on the shares themselves.
        $gatedComicIds = [];
        foreach ($receivedShares as $share) {
            if ($share->requiresAdultConfirmation() && $share->getComic() !== null) {
                $gatedComicIds[(int) $share->getComic()->getId()] = true;
            }
        }
        $reference = static function (?Comic $comic) use ($gatedComicIds): array {
            $gated = isset($gatedComicIds[(int) $comic?->getId()]);

            return [
                'comicId' => $gated ? null : $comic?->getId(),
                'comicTitle' => $gated ? null : $comic?->getTitle(),
            ];
        };

        $progress = [];
        foreach ($user->getReadingProgress() as $item) {
            $progress[] = $reference($item->getComic()) + [
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
                'username' => $user->getUsername(),
                'name' => $user->getName(),
                'roles' => $user->getRoles(),
                'emailVerified' => $user->isEmailVerified(),
                'createdAt' => $user->getCreatedAt()->format(\DateTimeInterface::ATOM),
                'updatedAt' => $user->getUpdatedAt()->format(\DateTimeInterface::ATOM),
                'lastLoginAt' => $user->getLastLoginAt()?->format(\DateTimeInterface::ATOM),
                'dropboxConnected' => $user->hasDropboxConnection(),
                'dropboxLastSyncedAt' => $user->getDropboxLastSyncedAt()?->format(\DateTimeInterface::ATOM),
                'readerPreferences' => $user->getReaderPreferences(),
                'connectedAccounts' => array_map(
                    static fn (UserOAuthIdentity $identity): array => [
                        'provider' => $identity->getProvider(),
                        'providerEmail' => $identity->getProviderEmail(),
                        'linkedAt' => $identity->getCreatedAt()->format(\DateTimeInterface::ATOM),
                        'lastUsedAt' => $identity->getLastUsedAt()?->format(\DateTimeInterface::ATOM),
                    ],
                    $user->getOAuthIdentities()->toArray(),
                ),
            ],
            'comics' => $comics,
            'readingProgress' => $progress,
            'personalTags' => $tags,
            'libraryFolders' => array_map(
                static fn (LibraryFolder $folder): array => [
                    'id' => $folder->getId(),
                    'name' => $folder->getName(),
                    'parentId' => $folder->getParent()?->getId(),
                    'createdAt' => $folder->getCreatedAt()->format(\DateTimeInterface::ATOM),
                    'updatedAt' => $folder->getUpdatedAt()->format(\DateTimeInterface::ATOM),
                ],
                $this->folderRepository->findForOwner($user),
            ),
            'libraryFolderPlacements' => array_map(
                static fn (LibraryFolderItem $item): array => $reference($item->getComic()) + [
                    'folderId' => $item->getFolder()?->getId(),
                    'placedAt' => $item->getCreatedAt()->format(\DateTimeInterface::ATOM),
                ],
                $this->folderItemRepository->findBy(['user' => $user], ['id' => 'ASC']),
            ),
            'sharesGranted' => array_map($this->mapSentShare(...), $sentShares),
            'sharesReceived' => array_map($this->mapReceivedShare(...), $receivedShares),
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
            'sourceType' => $comic->getSourceType()->value,
            'series' => $comic->getSeries(),
            'issueNumber' => $comic->getIssueNumber(),
            'issueCount' => $comic->getIssueCount(),
            'volume' => $comic->getVolume(),
            'publishedAt' => $comic->getPublishedAt()?->format('Y-m-d'),
            'languageCode' => $comic->getLanguageCode(),
            'ageRating' => $comic->getAgeRating(),
            'readingDirection' => $comic->getReadingDirection()->value,
            'creators' => $comic->getCreators(),
            'classification' => $comic->getClassification()->jsonSerialize() ?: null,
            'metadataOrigin' => $comic->getMetadataProvider() === null ? null : [
                'provider' => $comic->getMetadataProvider(),
                'externalId' => $comic->getMetadataExternalId(),
                'fetchedAt' => $comic->getMetadataFetchedAt()?->format(\DateTimeInterface::ATOM),
            ],
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
     * A grant as its owner is told about it. A recipient reached through a code
     * or username keeps the address the owner was never given, here as on the
     * Sharing page, so both read the owner's serializer view.
     *
     * @return array<string, mixed>
     */
    private function mapSentShare(ComicShare $share): array
    {
        $view = $this->shareSerializer->forOwner($share);

        return $this->mapShare($share, $view) + [
            'recipientEmail' => $view['recipientEmail'],
            'recipientLabel' => $view['recipientLabel'],
        ];
    }

    /**
     * A grant as its recipient is told about it. The comic stays unnamed until
     * they have passed the age gate, here as on the Sharing page. The address is
     * their own.
     *
     * @return array<string, mixed>
     */
    private function mapReceivedShare(ComicShare $share): array
    {
        return $this->mapShare($share, $this->shareSerializer->forRecipient($share)) + [
            'recipientEmail' => $share->getRecipientEmailNormalized(),
        ];
    }

    /**
     * The comic reference falls back to the snapshot so a tombstoned share is
     * still recognisable in an export.
     *
     * @param array<string, mixed> $view the serializer's view for this reader
     * @return array<string, mixed>
     */
    private function mapShare(ComicShare $share, array $view): array
    {
        return [
            'comicId' => $view['comicId'],
            'comicTitle' => $view['comicTitle'],
            'ownerUserId' => $share->getOwner()?->getId(),
            'ownerName' => $share->getOwnerNameSnapshot(),
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
        ];
    }
}
