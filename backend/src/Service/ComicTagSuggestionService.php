<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Comic;
use App\Entity\Tag;
use App\Entity\User;
use App\Metadata\Classification;
use App\Metadata\TagSuggestion;
use App\Repository\TagRepository;

/**
 * Tags a comic looks like it belongs to.
 *
 * Two sources, one list. Tags the library already has whose names appear in the
 * comic's own fields; and genres a file or a provider proposed, which are the
 * only classification values ever offered as tags at all.
 *
 * Characters, teams, locations and story arcs are deliberately never offered.
 * A single crossover names dozens of them, and a large collection enriched that
 * way would end up with thousands of tags nobody chose — which is the specific
 * failure issue #74 exists to prevent.
 *
 * Nothing here writes. Accepting a suggestion goes through the ordinary comic
 * update, which reuses an existing global or personal tag by name and otherwise
 * creates a personal one. A metadata import can never create a global tag.
 */
final class ComicTagSuggestionService
{
    /** Enough to be useful, few enough that the section stays ignorable. */
    private const MAX_GENRE_SUGGESTIONS = 12;

    public function __construct(private readonly TagRepository $tags)
    {
    }

    /**
     * @param Classification|null $proposed classification from a candidate under review, if any
     * @param string              $proposedSource where that classification came from
     * @return list<TagSuggestion>
     */
    public function for(
        Comic $comic,
        User $viewer,
        ?Classification $proposed = null,
        string $proposedSource = 'provider'
    ): array {
        $alreadyOn = [];
        foreach ($comic->getTags() as $tag) {
            $alreadyOn[mb_strtolower((string) $tag->getName())] = true;
        }

        $available = $this->tags->findAvailableForUser($viewer);

        $suggestions = $this->fromLibrary($comic, $available, $alreadyOn);

        // A suggestion already offered as an existing library tag is not worth
        // offering twice under a different heading.
        $offered = $alreadyOn;
        foreach ($suggestions as $suggestion) {
            $offered[mb_strtolower($suggestion->name)] = true;
        }

        return array_merge($suggestions, $this->fromGenres($comic, $proposed, $proposedSource, $available, $offered));
    }

    /**
     * @param list<Tag>             $available
     * @param array<string, bool>   $alreadyOn
     * @return list<TagSuggestion>
     */
    private function fromLibrary(Comic $comic, array $available, array $alreadyOn): array
    {
        // What the comic says about itself, in the order a match is most likely
        // to mean something. Publisher first: it is the field that actually
        // separates a Marvel comic from a DC one.
        $haystacks = array_filter([
            'publisher' => $comic->getPublisher(),
            'series' => $comic->getSeries(),
            'title' => $comic->getTitle(),
            'author' => $comic->getAuthor(),
            'creators' => implode(' ', array_merge(...array_values($comic->getCreators() ?: [[]]))),
        ], static fn (?string $value): bool => $value !== null && trim($value) !== '');

        $suggestions = [];

        foreach ($available as $tag) {
            $name = (string) $tag->getName();
            if ($name === '' || isset($alreadyOn[mb_strtolower($name)])) {
                continue;
            }

            $match = $this->firstMatch($name, $haystacks);
            if ($match === null) {
                continue;
            }

            [$field, $value] = $match;
            $suggestions[] = TagSuggestion::fromLibrary($name, $tag->isGlobal(), $field, $value);
        }

        return $suggestions;
    }

    /**
     * Genres from the file and from the record under review.
     *
     * The spelling of an existing tag wins over the source's, so accepting
     * "science fiction" from a provider lands on the user's own "Science
     * Fiction" rather than creating a second tag beside it.
     *
     * @param list<Tag>           $available
     * @param array<string, bool> $offered
     * @return list<TagSuggestion>
     */
    private function fromGenres(
        Comic $comic,
        ?Classification $proposed,
        string $proposedSource,
        array $available,
        array $offered
    ): array {
        $existing = [];
        foreach ($available as $tag) {
            $name = (string) $tag->getName();
            if ($name !== '') {
                $existing[mb_strtolower($name)] ??= $tag;
            }
        }

        $sources = ['comicinfo' => $comic->getClassification()->genres];
        if ($proposed !== null && $proposed->genres !== []) {
            $sources[$proposedSource] = $proposed->genres;
        }

        $suggestions = [];

        foreach ($sources as $source => $genres) {
            foreach ($genres as $genre) {
                $key = mb_strtolower($genre);
                if (isset($offered[$key]) || count($suggestions) >= self::MAX_GENRE_SUGGESTIONS) {
                    continue;
                }

                $offered[$key] = true;
                $match = $existing[$key] ?? null;

                $suggestions[] = TagSuggestion::genre(
                    $match !== null ? (string) $match->getName() : $genre,
                    (string) $source,
                    $match !== null,
                    $match?->isGlobal() ?? false,
                );
            }
        }

        return $suggestions;
    }

    /**
     * @param array<string, string> $haystacks
     * @return array{0: string, 1: string}|null
     */
    private function firstMatch(string $tagName, array $haystacks): ?array
    {
        $needle = self::words($tagName);
        if ($needle === []) {
            return null;
        }

        foreach ($haystacks as $field => $value) {
            if (self::containsSequence(self::words($value), $needle)) {
                return [$field, $value];
            }
        }

        return null;
    }

    /**
     * Whole words only, so a two-letter publisher tag like "dc" does not match
     * every comic with "abduction" in the title.
     *
     * @return list<string>
     */
    private static function words(string $value): array
    {
        $normalised = mb_strtolower((string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', $value));

        return array_values(array_filter(explode(' ', $normalised), static fn (string $w): bool => $w !== ''));
    }

    /**
     * @param list<string> $haystack
     * @param list<string> $needle
     */
    private static function containsSequence(array $haystack, array $needle): bool
    {
        $span = count($needle);
        if ($span === 0 || count($haystack) < $span) {
            return false;
        }

        for ($start = 0; $start + $span <= count($haystack); ++$start) {
            if (array_slice($haystack, $start, $span) === $needle) {
                return true;
            }
        }

        return false;
    }
}
