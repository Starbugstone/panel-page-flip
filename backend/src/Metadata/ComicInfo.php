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
    /** What each stored field's column holds, in characters. */
    public const MAX_SERIES_LENGTH = 255;
    public const MAX_ISSUE_NUMBER_LENGTH = 50;
    public const MAX_PUBLISHER_LENGTH = 255;
    public const MAX_AGE_RATING_LENGTH = 32;
    public const MAX_AUTHOR_LENGTH = 255;

    /** A TEXT column is limited in bytes, whatever the characters are. */
    public const MAX_SUMMARY_BYTES = 65_535;

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

    /**
     * The writers as one line, as much of it as the author column can hold.
     *
     * Names are kept whole while they fit, because a credit that ends halfway
     * through somebody's name is worse than one that lists fewer people.
     */
    public function writerCredit(): ?string
    {
        $names = $this->creators['writer'] ?? [];
        if ($names === []) {
            return null;
        }

        $credit = mb_substr($names[0], 0, self::MAX_AUTHOR_LENGTH);
        foreach (array_slice($names, 1) as $name) {
            $extended = $credit.', '.$name;
            if (mb_strlen($extended) > self::MAX_AUTHOR_LENGTH) {
                break;
            }

            $credit = $extended;
        }

        return $credit;
    }

    /** @return list<array<string, mixed>> */
    public function pagesAsArray(): array
    {
        return array_map(static fn (ComicPageInfo $page): array => $page->jsonSerialize(), $this->pages);
    }
}
