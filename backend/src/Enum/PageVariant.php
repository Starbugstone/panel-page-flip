<?php

namespace App\Enum;

/**
 * The finite set of sizes a page may be asked for.
 *
 * Closed on purpose. A free-form width parameter would let one reader mint an
 * unbounded number of cache keys, and every miss costs a full-size scan being
 * decoded and re-encoded — a handful of readers scrolling a zoom slider would
 * be indistinguishable from an attack.
 */
enum PageVariant: string
{
    case Thumb = 'thumb';
    case Small = 'reader-small';
    case Medium = 'reader-medium';
    case Large = 'reader-large';
    case Original = 'original';

    /**
     * What a request naming no variant gets.
     *
     * The source page's own dimensions, which is what the reader received
     * before variants existed: a client that has not been taught to ask for a
     * bounded size must not silently start receiving a smaller image than the
     * one it is displaying.
     */
    public const DEFAULT = self::Original;

    /** Widest this variant may be delivered; null leaves the page as it is. */
    public function maxWidth(): ?int
    {
        return match ($this) {
            self::Thumb => 280,
            self::Small => 800,
            self::Medium => 1400,
            self::Large => 2200,
            self::Original => null,
        };
    }

    public function quality(): int
    {
        // A thumbnail is shown at a fraction of its own size, so the artefacts
        // higher quality buys back are invisible there and the bytes are not.
        return $this === self::Thumb ? 68 : 82;
    }

    public static function fromRequestValue(?string $value): ?self
    {
        if ($value === null || $value === '') return self::DEFAULT;

        return self::tryFrom($value);
    }

    /** @return array<string, int|null> the variant ladder, for clients to select from */
    public static function widths(): array
    {
        $widths = [];
        foreach (self::cases() as $variant) $widths[$variant->value] = $variant->maxWidth();

        return $widths;
    }
}
