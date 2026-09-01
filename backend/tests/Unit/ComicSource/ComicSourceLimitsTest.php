<?php

declare(strict_types=1);

namespace App\Tests\Unit\ComicSource;

use App\ComicSource\ComicSourceLimits;
use PHPUnit\Framework\TestCase;

final class ComicSourceLimitsTest extends TestCase
{
    public function testArchiveAndMetadataLimitsStayBounded(): void
    {
        self::assertSame(10_000, ComicSourceLimits::MAX_ENTRIES);
        self::assertSame(2_147_483_648, ComicSourceLimits::MAX_UNCOMPRESSED_BYTES);
        self::assertSame(67_108_864, ComicSourceLimits::MAX_PAGE_BYTES);
        self::assertSame(2_097_152, ComicSourceLimits::MAX_METADATA_BYTES);
        self::assertSame(100, ComicSourceLimits::MAX_EXPANSION_RATIO);
        self::assertSame(1_024, ComicSourceLimits::MAX_ENTRY_PATH_BYTES);
        self::assertSame(16, ComicSourceLimits::MAX_ENTRY_DEPTH);
        self::assertSame(16_777_216, ComicSourceLimits::MAX_LISTING_BYTES);
        self::assertLessThan(ComicSourceLimits::MAX_UNCOMPRESSED_BYTES, ComicSourceLimits::MAX_PAGE_BYTES);
        self::assertLessThan(ComicSourceLimits::MAX_PAGE_BYTES, ComicSourceLimits::MAX_METADATA_BYTES);
    }

    public function testExpansionRatioIncludesTheArchiveAtTheBoundary(): void
    {
        self::assertFalse(ComicSourceLimits::hasUnsafeExpansionRatio(10_000, 100));
        self::assertTrue(ComicSourceLimits::hasUnsafeExpansionRatio(10_001, 100));
        self::assertTrue(ComicSourceLimits::hasUnsafeExpansionRatio(1, 0));
        self::assertFalse(ComicSourceLimits::hasUnsafeExpansionRatio(0, 0));
    }

    public function testByteLimitsIncludeTheBoundary(): void
    {
        self::assertFalse(ComicSourceLimits::wouldExceedByteLimit(16_777_200, 16, ComicSourceLimits::MAX_LISTING_BYTES));
        self::assertTrue(ComicSourceLimits::wouldExceedByteLimit(16_777_200, 17, ComicSourceLimits::MAX_LISTING_BYTES));
        self::assertTrue(ComicSourceLimits::wouldExceedByteLimit(ComicSourceLimits::MAX_PAGE_BYTES, 1, ComicSourceLimits::MAX_PAGE_BYTES));
    }
}
