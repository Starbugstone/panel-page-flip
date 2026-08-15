<?php

namespace App\Service;

use App\Entity\Comic;
use App\Entity\ComicReadingProgress;
use App\Entity\ComicShare;
use App\Entity\User;
use App\Repository\ComicReadingProgressRepository;
use App\Repository\ComicRepository;
use App\Repository\ComicShareRepository;

/**
 * Single source of truth for the shape of a comic in API responses.
 *
 * Every endpoint returning a comic goes through here so the payload cannot
 * drift between list, detail and upload responses — and so internal storage
 * paths never reach the client.
 */
class ComicSerializer
{
    public function __construct(
        private readonly ComicReadingProgressRepository $progressRepository,
        private readonly ComicRepository $comicRepository,
        private readonly ComicShareRepository $shareRepository
    ) {
    }

    /**
     * @param list<Comic> $comics
     * @return list<array<string, mixed>>
     */
    public function serializeMany(array $comics, User $viewer, bool $includeOwner = false): array
    {
        // All four lookups are batched on purpose: a library page reads every
        // comic's tags, owner, progress and sharing state, and doing that per
        // comic is what makes the list endpoint slow enough to be visible.
        $this->comicRepository->preloadAssociations($comics);
        $progressByComicId = $this->progressRepository->findByUserIndexedByComic($viewer, $comics);
        $sharesByComicId = $this->shareRepository->findAccessIndexedByComic($viewer, $comics);
        $shareCountsByComicId = $this->shareRepository->countActiveSharesByComic($comics);

        $serialized = [];
        foreach ($comics as $comic) {
            $serialized[] = $this->build(
                $comic,
                $viewer,
                $progressByComicId[$comic->getId()] ?? null,
                $sharesByComicId[$comic->getId()] ?? null,
                $shareCountsByComicId[$comic->getId()] ?? 0,
                $includeOwner
            );
        }

        return $serialized;
    }

    /**
     * @return array<string, mixed>
     */
    public function serialize(Comic $comic, User $viewer, bool $includeOwner = true): array
    {
        $isOwner = $comic->getOwner()?->getId() === $viewer->getId();

        return $this->build(
            $comic,
            $viewer,
            $this->progressRepository->findByUserAndComic($viewer, $comic),
            $isOwner ? null : $this->shareRepository->findAccessFor($viewer, $comic),
            $isOwner ? $this->shareRepository->countLiveSharesForComic($comic) : 0,
            $includeOwner
        );
    }

    /**
     * Public URL for a comic cover, or null when it has none. Built by hand
     * rather than through the router so internal container hostnames never leak
     * into generated URLs.
     */
    public function coverUrl(Comic $comic): ?string
    {
        $coverPath = $comic->getCoverImagePath();
        $ownerId = $comic->getOwner()?->getId();

        if (!$coverPath || $ownerId === null) {
            return null;
        }

        return sprintf('/api/comics/cover/%d/%d/%s', $ownerId, $comic->getId(), basename($coverPath));
    }

    /**
     * @return array<string, mixed>
     */
    private function build(
        Comic $comic,
        User $viewer,
        ?ComicReadingProgress $progress,
        ?ComicShare $share,
        int $sharedWithCount,
        bool $includeOwner
    ): array {
        $owner = $comic->getOwner();
        $isOwner = $owner?->getId() === $viewer->getId();
        $isAdmin = in_array('ROLE_ADMIN', $viewer->getRoles(), true);

        $data = [
            'id' => $comic->getId(),
            'title' => $comic->getTitle(),
            'author' => $comic->getAuthor(),
            'publisher' => $comic->getPublisher(),
            'description' => $comic->getDescription(),
            'coverImagePath' => $this->coverUrl($comic),
            'pageCount' => $comic->getPageCount(),
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
            // Characters, teams, locations, story arcs and genres. Structured
            // metadata, not tags — the editor offers genres as tag suggestions
            // and the user decides; nothing here is a tag until they do.
            // null rather than an empty object when there is nothing to say,
            // matching what a provider candidate serialises to.
            'classification' => $comic->getClassification()->jsonSerialize() ?: null,
            'metadataOrigin' => $comic->getMetadataProvider() === null ? null : [
                'provider' => $comic->getMetadataProvider(),
                'externalId' => $comic->getMetadataExternalId(),
                'fetchedAt' => $comic->getMetadataFetchedAt()?->format('c'),
            ],
            // The owner's own classification, independent of every tag. It is
            // what an 18+ gate is derived from when the comic is shared, and it
            // has to survive a round trip through the edit dialog unchanged.
            'explicitContent' => $comic->isExplicitContent(),
            'fileSize' => $comic->getFileSize(),
            'uploadedAt' => $comic->getUploadedAt()?->format('c'),
            'updatedAt' => $comic->getUpdatedAt()?->format('c'),
            // isGlobal/hideFromLibrary let the client badge a tag and know why a
            // comic is missing from the default library view.
            'tags' => array_map(
                static fn ($tag) => [
                    'id' => $tag->getId(),
                    'name' => $tag->getName(),
                    'isGlobal' => $tag->isGlobal(),
                    'hideFromLibrary' => $tag->hidesFromLibrary(),
                ],
                $comic->getTags()->toArray()
            ),
            'readingProgress' => $progress ? [
                'currentPage' => $progress->getCurrentPage(),
                'lastReadAt' => $progress->getLastReadAt()?->format('c'),
                'completed' => $progress->isCompleted(),
                // Lets a reader continue the revision sequence across sessions
                // instead of restarting below the stored value.
                'revision' => $progress->getRevision(),
            ] : null,
            // What this viewer is allowed to do, so the client does not have to
            // re-derive ownership rules that the voter already owns. A recipient
            // gets none of them.
            'isOwner' => $isOwner,
            'isShared' => $share !== null,
            'sharedBy' => $share !== null ? [
                'id' => $owner?->getId(),
                'name' => $share->getOwnerNameSnapshot(),
            ] : null,
            'shareId' => $share?->getId(),
            // Only meaningful to the owner; a recipient is never told who else
            // the comic was shared with.
            'sharedWithCount' => $isOwner ? $sharedWithCount : null,
            'canEdit' => $isOwner || $isAdmin,
            'canDelete' => $isOwner || $isAdmin,
            'sharingRestricted' => $comic->isSharingRestricted(),
            'contentQuarantined' => $comic->isQuarantined(),
            'canShare' => $isOwner
                && !$viewer->isSharingRestricted()
                && !$comic->isSharingRestricted()
                && !$comic->isQuarantined(),
        ];

        if ($includeOwner) {
            $data['owner'] = [
                'id' => $owner?->getId(),
                'email' => $owner?->getEmail(),
                'name' => $owner?->getName(),
            ];
        }

        return $data;
    }
}
