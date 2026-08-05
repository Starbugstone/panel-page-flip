<?php

namespace App\Service;

use App\Entity\Comic;
use App\Entity\ComicReadingProgress;
use App\Entity\User;
use App\Repository\ComicReadingProgressRepository;

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
        private readonly ComicReadingProgressRepository $progressRepository
    ) {
    }

    /**
     * @param list<Comic> $comics
     * @return list<array<string, mixed>>
     */
    public function serializeMany(array $comics, User $viewer, bool $includeOwner = false): array
    {
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
            'tags' => array_map(
                static fn ($tag) => ['id' => $tag->getId(), 'name' => $tag->getName()],
                $comic->getTags()->toArray()
            ),
            'readingProgress' => $progress ? [
                'currentPage' => $progress->getCurrentPage(),
                'lastReadAt' => $progress->getLastReadAt()?->format('c'),
                'completed' => $progress->isCompleted(),
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
