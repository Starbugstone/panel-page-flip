<?php

namespace App\Tests\Unit\ComicSource;

use App\ComicSource\SevenZipPageProvider;
use App\Enum\ComicSourceType;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

final class SevenZipComicInfoSourceTest extends TestCase
{
    private ?string $directory = null;

    /** @dataProvider archiveProvider */
    public function testReadsComicInfoOutOfEachArchiveFormat(ComicSourceType $type, string $sevenZipType): void
    {
        $archive = $this->archive($type, $sevenZipType, ['ComicInfo.xml' => '<ComicInfo><Series>Akira</Series></ComicInfo>']);

        self::assertSame(
            '<ComicInfo><Series>Akira</Series></ComicInfo>',
            trim((string) (new SevenZipPageProvider())->readComicInfoXml($archive, $type))
        );
    }

    public function testReturnsNullWhenTheArchiveHasNone(): void
    {
        $archive = $this->archive(ComicSourceType::CB7, '7z', ['page-1.jpg' => "\xFF\xD8\xFF\xE0page"]);

        self::assertNull((new SevenZipPageProvider())->readComicInfoXml($archive, ComicSourceType::CB7));
    }

    /** Metadata is an enrichment; a source it cannot read must not raise. */
    public function testReturnsNullRatherThanThrowingForAnUnreadableArchive(): void
    {
        $this->directory = sys_get_temp_dir().'/comic-7z-info-'.bin2hex(random_bytes(6));
        self::assertTrue(mkdir($this->directory, 0700));
        $archive = $this->directory.'/broken.cb7';
        file_put_contents($archive, 'not an archive');

        self::assertNull((new SevenZipPageProvider())->readComicInfoXml($archive, ComicSourceType::CB7));
    }

    public function testStopsReadingComicInfoAtTheMetadataLimit(): void
    {
        $archive = $this->archive(ComicSourceType::CB7, '7z', [
            'ComicInfo.xml' => str_repeat('a', 3_000_000),
        ]);

        self::assertNull((new SevenZipPageProvider())->readComicInfoXml($archive, ComicSourceType::CB7));
    }

    public function archiveProvider(): iterable
    {
        yield 'CB7' => [ComicSourceType::CB7, '7z'];
        yield 'CBT' => [ComicSourceType::CBT, 'tar'];
    }

    /** @param array<string, string> $entries */
    private function archive(ComicSourceType $type, string $sevenZipType, array $entries): string
    {
        if ((new ExecutableFinder())->find('7z') === null) self::markTestSkipped('7z is not installed.');

        $this->directory = sys_get_temp_dir().'/comic-7z-info-'.bin2hex(random_bytes(6));
        self::assertTrue(mkdir($this->directory, 0700));

        foreach ($entries as $name => $contents) {
            file_put_contents($this->directory.'/'.$name, $contents);
        }

        $archive = $this->directory.'/comic.'.$type->value;
        (new Process(array_merge(['7z', 'a', '-t'.$sevenZipType, $archive], array_keys($entries)), $this->directory))->mustRun();

        return $archive;
    }

    protected function tearDown(): void
    {
        if ($this->directory !== null && is_dir($this->directory)) {
            foreach (glob($this->directory.'/*') ?: [] as $file) unlink($file);
            rmdir($this->directory);
        }
        $this->directory = null;
        parent::tearDown();
    }
}
