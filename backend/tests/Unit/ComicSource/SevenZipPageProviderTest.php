<?php

namespace App\Tests\Unit\ComicSource;

use App\ComicSource\SevenZipPageProvider;
use App\Enum\ComicSourceType;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

final class SevenZipPageProviderTest extends TestCase
{
    private ?string $directory = null;

    /** @dataProvider archiveProvider */
    public function testInspectsAndReadsSupportedArchives(ComicSourceType $type, string $sevenZipType): void
    {
        if ((new ExecutableFinder())->find('7z') === null) self::markTestSkipped('7z is not installed.');
        $this->directory = sys_get_temp_dir().'/comic-7z-test-'.bin2hex(random_bytes(6));
        self::assertTrue(mkdir($this->directory, 0700));
        file_put_contents($this->directory.'/page-10.jpg', $this->jpeg('ten'));
        file_put_contents($this->directory.'/page-2.jpg', $this->jpeg('two'));
        $archive = $this->directory.'/comic.'.$type->value;

        $create = new Process(['7z', 'a', '-t'.$sevenZipType, $archive, 'page-10.jpg', 'page-2.jpg'], $this->directory);
        $create->mustRun();

        $provider = new SevenZipPageProvider();
        self::assertSame(2, $provider->inspect($archive, $type)->pageCount);
        self::assertSame($this->jpeg('two'), $provider->readPage($archive, $type, 1)->content);
    }

    public function archiveProvider(): iterable
    {
        yield 'CB7' => [ComicSourceType::CB7, '7z'];
        yield 'CBT' => [ComicSourceType::CBT, 'tar'];
    }

    private function jpeg(string $marker): string
    {
        return "\xFF\xD8\xFF\xE0".$marker;
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
