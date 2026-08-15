<?php

declare(strict_types=1);

namespace App\Metadata;

/**
 * A tag the comic already looks like it belongs to.
 *
 * Only ever an existing tag — one of the user's own, or a global one. Nothing
 * here invents a tag, because the whole point of keeping provider data out of
 * the tag system is that a library's categories are the librarian's to decide.
 */
final class TagSuggestion implements \JsonSerializable
{
    public function __construct(
        public readonly string $name,
        public readonly bool $isGlobal,
        /** The field that made this look like a match, for showing a reason. */
        public readonly string $matchedField,
        public readonly string $matchedValue,
    ) {
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'name' => $this->name,
            'isGlobal' => $this->isGlobal,
            'matchedField' => $this->matchedField,
            'matchedValue' => $this->matchedValue,
        ];
    }
}
