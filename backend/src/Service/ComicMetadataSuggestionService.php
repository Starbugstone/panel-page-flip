<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Comic;
use App\Enum\MetadataSource;
use App\Metadata\ComicFilenameParser;
use App\Metadata\MetadataSuggestion;

/**
 * What could be filled in about a comic, and where each proposal came from.
 *
 * Proposals only. Nothing here writes, and a field the comic already agrees
 * with is not worth proposing, so only genuine differences come back.
 */
final class ComicMetadataSuggestionService
{
    public function __construct(private readonly ComicFilenameParser $filenameParser)
    {
    }

    /** @return list<MetadataSuggestion> */
    public function for(Comic $comic): array
    {
        $guess = $this->filenameParser->parse($comic->getOriginalFilename() ?? '');
        if ($guess === null) {
            return [];
        }

        $suggestions = [
            $this->propose('series', $comic->getSeries(), $guess->series),
            $this->propose('issueNumber', $comic->getIssueNumber(), $guess->issueNumber),
            $this->propose('volume', $comic->getVolume(), $guess->volume),
            $this->propose('publishedYear', $comic->getPublishedAt()?->format('Y'), $guess->year),
        ];

        return array_values(array_filter($suggestions));
    }

    private function propose(string $field, string|int|null $current, string|int|null $suggested): ?MetadataSuggestion
    {
        if ($suggested === null || (string) $current === (string) $suggested) {
            return null;
        }

        return new MetadataSuggestion($field, $current, $suggested, MetadataSource::Filename);
    }
}
