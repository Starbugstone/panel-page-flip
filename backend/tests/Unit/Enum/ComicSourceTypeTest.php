<?php

declare(strict_types=1);

namespace App\Tests\Unit\Enum;

use App\Enum\ComicSourceType;
use PHPUnit\Framework\TestCase;

final class ComicSourceTypeTest extends TestCase
{
    public function testFromFilenameReadsTheExtension(): void
    {
        self::assertSame(ComicSourceType::CBZ, ComicSourceType::fromFilename('Issue 01.CBZ'));
        self::assertSame(ComicSourceType::PDF, ComicSourceType::fromFilename('/tmp/scan.pdf'));
    }

    public function testFromFilenameRejectsAnUnknownExtension(): void
    {
        $this->expectException(\RuntimeException::class);
        ComicSourceType::fromFilename('notes.txt');
    }

    public function testExtensionsListsEveryCase(): void
    {
        self::assertSame(['cbz', 'cbr', 'cb7', 'cbt', 'pdf'], ComicSourceType::extensions());
    }

    public function testMimeTypeIsStablePerFormat(): void
    {
        self::assertSame('application/vnd.comicbook+zip', ComicSourceType::CBZ->mimeType());
        self::assertSame('application/pdf', ComicSourceType::PDF->mimeType());
    }
}
