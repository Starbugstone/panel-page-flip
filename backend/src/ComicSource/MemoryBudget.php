<?php

namespace App\ComicSource;

/**
 * What this process can still allocate before PHP ends it.
 *
 * Running out of memory is a fatal error rather than an exception: no catch and
 * no finally runs, so a renderer fallback never happens and an upload's cleanup
 * is skipped, stranding the copied file outside the owner's quota. Anything
 * sized by an uploaded file therefore has to ask before it allocates.
 */
final class MemoryBudget
{
    /**
     * Kept free for what is too small and too frequent to ask about one by one:
     * array growth, call frames, and the error handling that follows a refusal.
     */
    private const HEADROOM_BYTES = 16_777_216;

    public static function fits(int|float $bytes): bool
    {
        return $bytes <= self::available();
    }

    /** Bytes that may be allocated from here, which is PHP_INT_MAX when memory is unlimited. */
    public static function available(): int
    {
        $limit = self::limit();
        if ($limit === null) return PHP_INT_MAX;

        // Real usage is what PHP compares with the limit; the figure without
        // `true` leaves out the chunks a fragmented heap is holding on to.
        return max(0, $limit - memory_get_usage(true) - self::HEADROOM_BYTES);
    }

    private static function limit(): ?int
    {
        $configured = ini_get('memory_limit');
        if ($configured === false || $configured === '') return null;

        $bytes = @ini_parse_quantity($configured);

        // Only a negative limit means unlimited. Zero is not a synonym for it,
        // so it is left to refuse everything rather than to promise anything.
        return $bytes < 0 ? null : $bytes;
    }
}
