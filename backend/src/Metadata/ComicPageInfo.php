<?php

declare(strict_types=1);

namespace App\Metadata;

use App\Enum\ComicPageType;

/**
 * What is known about one page before it is decoded.
 *
 * This is the page-info contract the spread and derivative work consumes:
 * ComicInfo populates it today, and measuring the images can populate it later
 * for sources that carry no metadata.
 */
final class ComicPageInfo implements \JsonSerializable
{
    public function __construct(
        public readonly int $page,
        public readonly ?ComicPageType $type = null,
        public readonly bool $doublePage = false,
        public readonly ?int $width = null,
        public readonly ?int $height = null,
    ) {
    }

    /** @param array<string, mixed> $stored */
    public static function fromArray(array $stored): ?self
    {
        $page = $stored['page'] ?? null;
        if (!is_int($page) || $page < 1) {
            return null;
        }

        return new self(
            $page,
            ComicPageType::tryFromName(is_string($stored['type'] ?? null) ? $stored['type'] : null),
            ($stored['doublePage'] ?? false) === true,
            self::positiveInt($stored['width'] ?? null),
            self::positiveInt($stored['height'] ?? null),
        );
    }

    public function isWide(): bool
    {
        return $this->doublePage
            || ($this->width !== null && $this->height !== null && $this->width > $this->height);
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return array_filter([
            'page' => $this->page,
            'type' => $this->type?->value,
            'doublePage' => $this->doublePage ?: null,
            'width' => $this->width,
            'height' => $this->height,
        ], static fn ($value): bool => $value !== null);
    }

    private static function positiveInt(mixed $value): ?int
    {
        return is_int($value) && $value > 0 ? $value : null;
    }
}
