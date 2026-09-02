<?php

namespace App\Tests\Unit\ComicSource;

use App\ComicSource\ZipPageProvider;
use App\Enum\ComicSourceType;
use PHPUnit\Framework\TestCase;

final class ZipPageProviderTest extends TestCase
{
    private ?string $archivePath = null;

    public function testUsesOneSafeNaturalPageSequenceAndDetectsMimeFromBytes(): void
    {
        $this->archivePath = tempnam(sys_get_temp_dir(), 'comic-zip-');
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($this->archivePath, \ZipArchive::OVERWRITE) === true);
        $zip->addFromString('../escape.jpg', $this->jpeg('escape'));
        $zip->addFromString('__MACOSX/._page.jpg', $this->jpeg('noise'));
        $zip->addFromString('page-10.jpg', $this->jpeg('ten'));
        $zip->addFromString('page-2.jpg', $this->jpeg('two'));
        $zip->close();

        $provider = new ZipPageProvider();
        self::assertSame(['page-2.jpg', 'page-10.jpg'], $provider->pageIndex($this->archivePath));
        self::assertSame('image/jpeg', $provider->readPage($this->archivePath, ComicSourceType::CBZ, 1)->mimeType);
    }

    public function testRejectsAnImageExtensionWithNonImageContent(): void
    {
        $this->archivePath = tempnam(sys_get_temp_dir(), 'comic-zip-');
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($this->archivePath, \ZipArchive::OVERWRITE) === true);
        $zip->addFromString('page.jpg', 'not an image');
        $zip->close();

        $provider = new ZipPageProvider();
        $this->expectException(\RuntimeException::class);
        $provider->readPage($this->archivePath, ComicSourceType::CBZ, 1);
    }

    public function testRejectsAnArchiveWithAnUnsafeExpansionRatio(): void
    {
        $this->archivePath = tempnam(sys_get_temp_dir(), 'comic-zip-');
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($this->archivePath, \ZipArchive::OVERWRITE) === true);
        $zip->addFromString('page.jpg', str_repeat("\0", 1_048_576));
        $zip->close();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('unsafe compression ratio');

        (new ZipPageProvider())->pageIndex($this->archivePath);
    }

    public function testIgnoresImageNamesWithUnboundedPaths(): void
    {
        $atDepthLimit = implode('/', array_fill(0, 15, 'folder')).'/page.jpg';
        $pastDepthLimit = 'folder/'.$atDepthLimit;

        self::assertTrue(ZipPageProvider::isSafeImage($atDepthLimit));
        self::assertFalse(ZipPageProvider::isSafeImage($pastDepthLimit));
        self::assertFalse(ZipPageProvider::isSafeImage(str_repeat('a', 1_021).'.jpg'));
    }

    private function jpeg(string $marker): string
    {
        return "\xFF\xD8\xFF\xE0".$marker;
    }

    protected function tearDown(): void
    {
        if ($this->archivePath !== null && is_file($this->archivePath)) unlink($this->archivePath);
        $this->archivePath = null;
        parent::tearDown();
    }
}
