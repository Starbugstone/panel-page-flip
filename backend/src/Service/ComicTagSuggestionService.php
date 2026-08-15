<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Comic;
use App\Entity\Tag;
use App\Entity\User;
use App\Metadata\TagSuggestion;
use App\Repository\TagRepository;

/**
 * Tags the user already has that look like they belong to a comic.
 *
 * Proposes nothing that does not already exist. A provider saying a comic is
 * published by Marvel is not a reason to create a "marvel" tag in somebody's
 * library; it is a reason to point at the one they made themselves, if they
 * made one.
 */
final class ComicTagSuggestionService
{
    public function __construct(private readonly TagRepository $tags)
    {
    }

    /** @return list<TagSuggestion> */
    public function for(Comic $comic, User $viewer): array
    {
        $alreadyOn = [];
        foreach ($comic->getTags() as $tag) {
            $alreadyOn[mb_strtolower((string) $tag->getName())] = true;
        }

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

        foreach ($this->tags->findAvailableForUser($viewer) as $tag) {
            $name = (string) $tag->getName();
            if ($name === '' || isset($alreadyOn[mb_strtolower($name)])) {
                continue;
            }

            $match = $this->firstMatch($name, $haystacks);
            if ($match === null) {
                continue;
            }

            [$field, $value] = $match;
            $suggestions[] = new TagSuggestion($name, $tag->isGlobal(), $field, $value);
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
