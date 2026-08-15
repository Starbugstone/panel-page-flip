<?php

declare(strict_types=1);

namespace App\Metadata;

/**
 * What a source says a comic is *about*, as opposed to what it is.
 *
 * Kept apart from the tag system on purpose. A Marvel crossover names dozens of
 * characters, teams and arcs; turning those into tags would bury a library's
 * own categories under entity names nobody chose. Genres are the only part ever
 * offered as a tag, and only as a suggestion — see docs/metadata-enrichment.md.
 */
final class Classification implements \JsonSerializable
{
    /** A malformed or over-enthusiastic source cannot flood the UI. */
    private const MAX_VALUES_PER_FIELD = 40;
    private const MAX_VALUE_LENGTH = 120;

    /**
     * @param list<string> $genres
     * @param list<string> $characters
     * @param list<string> $teams
     * @param list<string> $locations
     * @param list<string> $storyArcs
     */
    public function __construct(
        public readonly array $genres = [],
        public readonly array $characters = [],
        public readonly array $teams = [],
        public readonly array $locations = [],
        public readonly array $storyArcs = [],
    ) {
    }

    /** @param array<string, mixed> $values */
    public static function fromArray(array $values): self
    {
        $field = static fn (string $name): array => self::clean($values[$name] ?? []);

        return new self(
            $field('genres'),
            $field('characters'),
            $field('teams'),
            $field('locations'),
            $field('storyArcs'),
        );
    }

    public function isEmpty(): bool
    {
        return $this->genres === []
            && $this->characters === []
            && $this->teams === []
            && $this->locations === []
            && $this->storyArcs === [];
    }

    /**
     * @return array<string, list<string>>
     */
    public function jsonSerialize(): array
    {
        return array_filter([
            'genres' => $this->genres,
            'characters' => $this->characters,
            'teams' => $this->teams,
            'locations' => $this->locations,
            'storyArcs' => $this->storyArcs,
        ], static fn (array $values): bool => $values !== []);
    }

    /**
     * Trim, collapse runs of whitespace, drop blanks and case-insensitive
     * repeats, and cap both the length of a value and how many there can be.
     * Everything here arrived from a file or a third party.
     *
     * @return list<string>
     */
    public static function clean(mixed $values): array
    {
        if (!is_array($values)) {
            return [];
        }

        $cleaned = [];

        foreach ($values as $value) {
            if (!is_string($value) && !is_int($value)) {
                continue;
            }

            $name = trim((string) preg_replace('/\s+/u', ' ', (string) $value));
            if ($name === '' || mb_strlen($name) > self::MAX_VALUE_LENGTH) {
                continue;
            }

            $cleaned[mb_strtolower($name)] ??= $name;

            if (count($cleaned) >= self::MAX_VALUES_PER_FIELD) {
                break;
            }
        }

        return array_values($cleaned);
    }
}
