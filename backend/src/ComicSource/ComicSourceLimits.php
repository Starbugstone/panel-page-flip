<?php

namespace App\ComicSource;

enum ComicSourceLimits
{
    public const MAX_ENTRIES = 10_000;
    public const MAX_UNCOMPRESSED_BYTES = 2_147_483_648;
    public const MAX_PAGE_BYTES = 67_108_864;
    public const MAX_METADATA_BYTES = 2_097_152;
    public const MAX_EXPANSION_RATIO = 100;
    public const MAX_ENTRY_PATH_BYTES = 1_024;
    public const MAX_ENTRY_DEPTH = 16;
    public const MAX_LISTING_BYTES = 16_777_216;

    public static function hasUnsafeExpansionRatio(int $expandedBytes, int $archiveBytes): bool
    {
        if ($expandedBytes <= 0) {
            return false;
        }

        return $archiveBytes <= 0 || $expandedBytes > $archiveBytes * self::MAX_EXPANSION_RATIO;
    }

    public static function wouldExceedByteLimit(int $receivedBytes, int $incomingBytes, int $limitBytes): bool
    {
        if ($limitBytes < 0 || $receivedBytes < 0 || $incomingBytes < 0 || $receivedBytes > $limitBytes) {
            return true;
        }

        return $incomingBytes > $limitBytes - $receivedBytes;
    }
}
