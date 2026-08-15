<?php

declare(strict_types=1);

namespace App\Metadata;

use App\Enum\ReadingDirection;

/**
 * A comic's own account of itself, as read from the file.
 *
 * Every field is optional: real ComicInfo.xml files fill in whatever their
 * producer cared about and leave the rest out.
 */
final class ComicInfo
{
    /**
     * @param array<string, list<string>> $creators role => names
     * @param list<ComicPageInfo>         $pages
     */
    public function __construct(
        public readonly ?string $title = null,
        public readonly ?string $series = null,
        public readonly ?string $issueNumber = null,
        public readonly ?int $issueCount = null,
        public readonly ?int $volume = null,
        public readonly ?string $publisher = null,
        public readonly ?string $summary = null,
        public readonly ?\DateTimeImmutable $publishedAt = null,
        public readonly ?string $languageCode = null,
        public readonly ?string $ageRating = null,
        public readonly ReadingDirection $readingDirection = ReadingDirection::LeftToRight,
        public readonly array $creators = [],
        public readonly ?Classification $classification = null,
        public readonly array $pages = [],
    ) {
    }

    public function isEmpty(): bool
    {
        return $this->title === null
            && $this->series === null
            && $this->issueNumber === null
            && $this->issueCount === null
            && $this->volume === null
            && $this->publisher === null
            && $this->summary === null
            && $this->publishedAt === null
            && $this->languageCode === null
            && $this->ageRating === null
            && $this->readingDirection === ReadingDirection::LeftToRight
            && $this->creators === []
            && ($this->classification === null || $this->classification->isEmpty())
            && $this->pages === [];
    }

    /** @return list<array<string, mixed>> */
    public function pagesAsArray(): array
    {
        return array_map(static fn (ComicPageInfo $page): array => $page->jsonSerialize(), $this->pages);
    }
}
