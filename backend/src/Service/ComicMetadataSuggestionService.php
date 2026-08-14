<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Comic;
use App\Enum\MetadataSource;
use App\Metadata\ComicFilenameParser;
use App\Metadata\MetadataSuggestion;
use App\Metadata\Provider\ProviderCandidate;

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

        return $this->collect($comic, MetadataSource::Filename, [
            'series' => $guess->series,
            'issueNumber' => $guess->issueNumber,
            'volume' => $guess->volume,
            'publishedAt' => $guess->year !== null ? sprintf('%d-01-01', $guess->year) : null,
        ]);
    }

    /**
     * The same per-field shape for a chosen provider record, so the review UI
     * has one thing to render and one thing to apply whatever the source.
     *
     * @return list<MetadataSuggestion>
     */
    public function fromCandidate(Comic $comic, ProviderCandidate $candidate): array
    {
        return $this->collect($comic, MetadataSource::Provider, [
            'series' => $candidate->series,
            'issueNumber' => $candidate->issueNumber,
            'volume' => $candidate->volume,
            'publisher' => $candidate->publisher,
            'description' => $candidate->summary,
            'publishedAt' => $candidate->publishedAt?->format('Y-m-d'),
        ]);
    }

    /**
     * @param array<string, string|int|null> $proposed
     * @return list<MetadataSuggestion>
     */
    private function collect(Comic $comic, MetadataSource $source, array $proposed): array
    {
        $current = [
            'series' => $comic->getSeries(),
            'issueNumber' => $comic->getIssueNumber(),
            'volume' => $comic->getVolume(),
            'publisher' => $comic->getPublisher(),
            'description' => $comic->getDescription(),
            'publishedAt' => $comic->getPublishedAt()?->format('Y-m-d'),
        ];

        $suggestions = [];

        foreach ($proposed as $field => $value) {
            if ($value === null || (string) ($current[$field] ?? null) === (string) $value) {
                continue;
            }

            $suggestions[] = new MetadataSuggestion($field, $current[$field] ?? null, $value, $source);
        }

        return $suggestions;
    }
}
