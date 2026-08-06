<?php

namespace App\Service;

use App\Entity\Comic;
use App\Entity\ComicReadingProgress;
use App\Entity\User;
use App\Repository\ComicReadingProgressRepository;
use App\Repository\ComicRepository;

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
        private readonly ComicRepository $comicRepository
    ) {
    }

    /**
     * @param list<Comic> $comics
     * @return list<array<string, mixed>>
     */
    public function serializeMany(array $comics, User $viewer, bool $includeOwner = false): array
    {
        // Both calls are batched on purpose: a library page reads every comic's
        // tags, owner and progress, and doing that per comic is what makes the
        // list endpoint slow enough to be visible.
        $this->comicRepository->preloadAssociations($comics);
        $progressByComicId = $this->progressRepository->findByUserIndexedByComic($viewer, $comics);

        $serialized = [];
        foreach ($comics as $comic) {
            $serialized[] = $this->build(
                $comic,
                $progressByComicId[$comic->getId()] ?? null,
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
        return $this->build(
            $comic,
            $this->progressRepository->findByUserAndComic($viewer, $comic),
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
    private function build(Comic $comic, ?ComicReadingProgress $progress, bool $includeOwner): array
    {
        $data = [
            'id' => $comic->getId(),
            'title' => $comic->getTitle(),
            'author' => $comic->getAuthor(),
            'publisher' => $comic->getPublisher(),
            'description' => $comic->getDescription(),
            'coverImagePath' => $this->coverUrl($comic),
            'pageCount' => $comic->getPageCount(),
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
        ];

        if ($includeOwner) {
            $owner = $comic->getOwner();
            $data['owner'] = [
                'id' => $owner?->getId(),
                'email' => $owner?->getEmail(),
                'name' => $owner?->getName(),
            ];
        }

        return $data;
    }
}
