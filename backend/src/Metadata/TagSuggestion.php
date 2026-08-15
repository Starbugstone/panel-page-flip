<?php

declare(strict_types=1);

namespace App\Metadata;

/**
 * A tag the comic looks like it belongs to, and where the idea came from.
 *
 * Two kinds, deliberately distinguished in the payload rather than merged:
 *
 * - a tag the library **already has** whose name appears in the comic's own
 *   fields, which accepting only assigns;
 * - a **genre** a file or a provider proposed, which accepting may turn into a
 *   new personal tag.
 *
 * The second kind is the one the issue warns about. A provider response can
 * name dozens of things, and a library whose categories were generated from
 * them is nobody's categorisation, so nothing here is selected by default and
 * nothing here writes.
 */
final class TagSuggestion implements \JsonSerializable
{
    public const KIND_LIBRARY = 'library';
    public const KIND_GENRE = 'genre';

    private function __construct(
        public readonly string $name,
        public readonly string $kind,
        public readonly bool $isGlobal,
        /** Whether a tag with this name already exists in the user's library. */
        public readonly bool $exists,
        /** Where the idea came from: 'library', 'comicinfo', or a provider key. */
        public readonly string $source,
        /** The field that made this look like a match, for showing a reason. */
        public readonly ?string $matchedField = null,
        public readonly ?string $matchedValue = null,
    ) {
    }

    public static function fromLibrary(string $name, bool $isGlobal, string $matchedField, string $matchedValue): self
    {
        return new self($name, self::KIND_LIBRARY, $isGlobal, true, 'library', $matchedField, $matchedValue);
    }

    /**
     * @param string $source 'comicinfo' or a provider key
     * @param bool   $exists whether the library already has a tag by this name
     */
    public static function genre(string $name, string $source, bool $exists, bool $isGlobal): self
    {
        return new self($name, self::KIND_GENRE, $isGlobal, $exists, $source);
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'name' => $this->name,
            'kind' => $this->kind,
            'isGlobal' => $this->isGlobal,
            'exists' => $this->exists,
            'source' => $this->source,
            'matchedField' => $this->matchedField,
            'matchedValue' => $this->matchedValue,
        ];
    }
}
