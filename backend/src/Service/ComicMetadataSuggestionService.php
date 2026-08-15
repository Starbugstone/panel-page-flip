<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Comic;
use App\Enum\MetadataSource;
use App\Metadata\ComicFilenameParser;
use App\Metadata\FilenameGuess;
use App\Metadata\MetadataSuggestion;
use App\Metadata\Provider\ProviderCandidate;

/**
 * What could be filled in about a comic, and where each proposal came from.
 *
 * Proposals only. Nothing here writes, and a field the comic already agrees
 * with is not worth proposing, so only genuine differences come back.
 *
 * `explicitContent` is deliberately absent and must stay absent. It is the
 * owner's declaration about their own library, and no age rating from a file or
 * a database is grounds for setting it on their behalf.
 */
final class ComicMetadataSuggestionService
{
    public function __construct(private readonly ComicFilenameParser $filenameParser)
    {
    }

    /** @return list<MetadataSuggestion> */
    public function for(Comic $comic): array
    {
        $guess = $this->guess($comic);
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

    public function guess(Comic $comic): ?FilenameGuess
    {
        return $this->filenameParser->parse($comic->getOriginalFilename() ?? '');
    }

    /**
     * The same per-field shape for a chosen provider record, so the review UI
     * has one thing to render and one thing to apply whatever the source.
     *
     * A search row carries only what the search returned; a detail lookup
     * carries the rest. Both go through here, and a field the record has
     * nothing to say about simply produces no suggestion.
     *
     * @return list<MetadataSuggestion>
     */
    public function fromCandidate(Comic $comic, ProviderCandidate $candidate): array
    {
        return $this->collect($comic, MetadataSource::Provider, [
            'title' => $candidate->title,
            'series' => $candidate->series,
            'issueNumber' => $candidate->issueNumber,
            'issueCount' => $candidate->issueCount,
            'volume' => $candidate->volume,
            'publisher' => $candidate->publisher,
            'description' => $candidate->summary,
            'publishedAt' => $candidate->publishedAt?->format('Y-m-d'),
            'languageCode' => $candidate->languageCode,
            'ageRating' => $candidate->ageRating,
            'creators' => $candidate->creators === [] ? null : $candidate->creators,
        ]);
    }

    /**
     * @param array<string, string|int|array<string, list<string>>|null> $proposed
     * @return list<MetadataSuggestion>
     */
    private function collect(Comic $comic, MetadataSource $source, array $proposed): array
    {
        $current = [
            'title' => $comic->getTitle(),
            'series' => $comic->getSeries(),
            'issueNumber' => $comic->getIssueNumber(),
            'issueCount' => $comic->getIssueCount(),
            'volume' => $comic->getVolume(),
            'publisher' => $comic->getPublisher(),
            'description' => $comic->getDescription(),
            'publishedAt' => $comic->getPublishedAt()?->format('Y-m-d'),
            'languageCode' => $comic->getLanguageCode(),
            'ageRating' => $comic->getAgeRating(),
            'creators' => $comic->getCreators(),
        ];

        $suggestions = [];

        foreach ($proposed as $field => $value) {
            if ($value === null) {
                continue;
            }

            $existing = $current[$field] ?? null;
            if ($this->same($existing, $value)) {
                continue;
            }

            $suggestions[] = new MetadataSuggestion($field, $existing === [] ? null : $existing, $value, $source);
        }

        return $suggestions;
    }

    /**
     * @param string|int|array<string, list<string>>|null $current
     * @param string|int|array<string, list<string>>      $proposed
     */
    private function same(string|int|array|null $current, string|int|array $proposed): bool
    {
        if (is_array($current) || is_array($proposed)) {
            return $this->normaliseCreators($current) === $this->normaliseCreators($proposed);
        }

        return (string) $current === (string) $proposed;
    }

    /**
     * Role order and case are not what makes two credit lists different.
     *
     * @param string|int|array<string, list<string>>|null $creators
     * @return array<string, list<string>>
     */
    private function normaliseCreators(string|int|array|null $creators): array
    {
        if (!is_array($creators)) {
            return [];
        }

        $normalised = [];
        foreach ($creators as $role => $names) {
            if (!is_array($names)) {
                continue;
            }

            $people = array_values(array_unique(array_filter(array_map(
                static fn (mixed $name): string => is_string($name) ? trim($name) : '',
                $names
            ))));

            if ($people !== []) {
                sort($people);
                $normalised[mb_strtolower((string) $role)] = $people;
            }
        }

        ksort($normalised);

        return $normalised;
    }
}
