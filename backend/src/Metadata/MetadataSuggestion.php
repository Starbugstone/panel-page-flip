<?php

declare(strict_types=1);

namespace App\Metadata;

use App\Enum\MetadataSource;

/**
 * One proposed change to one field, with what it would replace and where it
 * came from. Nothing acts on these; they exist to be shown to somebody.
 */
final class MetadataSuggestion implements \JsonSerializable
{
    public function __construct(
        public readonly string $field,
        public readonly string|int|null $current,
        public readonly string|int $suggested,
        public readonly MetadataSource $source,
    ) {
    }

    public function fillsAGap(): bool
    {
        return $this->current === null || $this->current === '';
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'field' => $this->field,
            'current' => $this->current,
            'suggested' => $this->suggested,
            'source' => $this->source->value,
            'fillsGap' => $this->fillsAGap(),
        ];
    }
}
