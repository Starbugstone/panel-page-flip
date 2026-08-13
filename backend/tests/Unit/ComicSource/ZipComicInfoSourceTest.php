<?php

namespace App\Tests\Unit\ComicSource;

use App\ComicSource\ZipPageProvider;
use App\Enum\ComicSourceType;
use PHPUnit\Framework\TestCase;

final class ZipComicInfoSourceTest extends TestCase
{
    private ?string $archivePath = null;

    public function testReadsComicInfoFromTheArchiveRoot(): void
    {
        $this->archive(['ComicInfo.xml' => '<ComicInfo><Series>Batman</Series></ComicInfo>']);

        self::assertSame(
            '<ComicInfo><Series>Batman</Series></ComicInfo>',
            (new ZipPageProvider())->readComicInfoXml($this->archivePath, ComicSourceType::CBZ)
        );
    }

    /** Producers disagree about the casing, and both spellings are common. */
    public function testAcceptsAnyCasingOfTheEntryName(): void
    {
        $this->archive(['comicinfo.xml' => '<ComicInfo><Series>Batman</Series></ComicInfo>']);

        self::assertNotNull((new ZipPageProvider())->readComicInfoXml($this->archivePath, ComicSourceType::CBZ));
    }

    /**
     * A ComicInfo.xml inside a subdirectory describes that subdirectory, not the
     * comic, so reading it would attribute one issue's metadata to another.
     */
    public function testIgnoresANestedComicInfo(): void
    {
        $this->archive(['extras/ComicInfo.xml' => '<ComicInfo><Series>Wrong</Series></ComicInfo>']);

        self::assertNull((new ZipPageProvider())->readComicInfoXml($this->archivePath, ComicSourceType::CBZ));
    }

    public function testReturnsNullWhenTheArchiveHasNone(): void
    {
        $this->archive(['page-1.jpg' => "\xFF\xD8\xFF\xE0page"]);

        self::assertNull((new ZipPageProvider())->readComicInfoXml($this->archivePath, ComicSourceType::CBZ));
    }

    public function testReturnsNullForAnUnreadableArchive(): void
    {
        $this->archivePath = tempnam(sys_get_temp_dir(), 'comic-zip-');
        file_put_contents($this->archivePath, 'not a zip');

        self::assertNull((new ZipPageProvider())->readComicInfoXml($this->archivePath, ComicSourceType::CBZ));
    }

    /** A metadata entry is capped independently of the page limit. */
    public function testTruncatesAnOversizedEntryRatherThanLoadingItWhole(): void
    {
        $this->archive(['ComicInfo.xml' => str_repeat('a', 3_000_000)]);

        $xml = (new ZipPageProvider())->readComicInfoXml($this->archivePath, ComicSourceType::CBZ);

        self::assertNotNull($xml);
        self::assertSame(2_097_152, strlen($xml));
    }

    /** @param array<string, string> $entries */
    private function archive(array $entries): void
    {
        $this->archivePath = tempnam(sys_get_temp_dir(), 'comic-zip-');
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($this->archivePath, \ZipArchive::OVERWRITE) === true);
        foreach ($entries as $name => $contents) {
            $zip->addFromString($name, $contents);
        }
        $zip->close();
    }

    protected function tearDown(): void
    {
        if ($this->archivePath !== null && is_file($this->archivePath)) unlink($this->archivePath);
        $this->archivePath = null;
        parent::tearDown();
    }
}
