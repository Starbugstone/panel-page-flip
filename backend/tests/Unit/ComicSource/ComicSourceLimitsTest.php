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
        self::assertLessThan(ComicSourceLimits::MAX_UNCOMPRESSED_BYTES, ComicSourceLimits::MAX_PAGE_BYTES);
        self::assertLessThan(ComicSourceLimits::MAX_PAGE_BYTES, ComicSourceLimits::MAX_METADATA_BYTES);
    }
}
