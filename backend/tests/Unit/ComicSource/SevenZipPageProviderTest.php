<?php

namespace App\Tests\Unit\ComicSource;

use App\ComicSource\SevenZipPageProvider;
use App\Enum\ComicSourceType;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\PhpExecutableFinder;
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

    /**
     * A page within the page limit can still be more than the memory left, and
     * running out is fatal, so it has to come back as an error instead.
     */
    public function testRefusesAPageLargerThanTheMemoryLeft(): void
    {
        if ((new ExecutableFinder())->find('7z') === null) self::markTestSkipped('7z is not installed.');

        $this->directory = sys_get_temp_dir().'/comic-7z-test-'.bin2hex(random_bytes(6));
        self::assertTrue(mkdir($this->directory, 0700));
        file_put_contents($this->directory.'/page.jpg', str_repeat('x', 40_000_000));
        $archive = $this->directory.'/comic.cbt';
        (new Process(['7z', 'a', '-ttar', $archive, 'page.jpg'], $this->directory))->mustRun();

        $this->assertMemoryLimitedReadIsRefused($archive);
    }

    public function testRefusesAContinuouslyWritingExtractorBeforeItsPipeFillsMemory(): void
    {
        $this->directory = sys_get_temp_dir().'/comic-7z-test-'.bin2hex(random_bytes(6));
        self::assertTrue(mkdir($this->directory, 0700));
        $archive = $this->directory.'/comic.cbt';
        file_put_contents($archive, str_repeat('x', 40_000_000));
        $extractor = $this->directory.'/7z';
        file_put_contents($extractor, <<<'SH'
            #!/bin/sh
            case "$1" in
                l) printf 'Type = tar\n----------\nPath = page.jpg\nSize = 40000000\n' ;;
                x) exec cat "$(dirname "$0")/comic.cbt" ;;
            esac
            SH);
        chmod($extractor, 0700);

        $this->assertMemoryLimitedReadIsRefused($archive, ['PATH' => $this->directory.':'.getenv('PATH')]);
    }

    /** @param array<string, string> $environment */
    private function assertMemoryLimitedReadIsRefused(string $archive, array $environment = []): void
    {
        $php = (new PhpExecutableFinder())->find();
        if ($php === false) self::markTestSkipped('No PHP binary to run the isolated check with.');

        $script = <<<'PHP'
            require getenv('APP_AUTOLOAD');
            try {
                (new App\ComicSource\SevenZipPageProvider())->readPage(getenv('APP_ARCHIVE'), App\Enum\ComicSourceType::CBT, 1);
                echo 'read';
            } catch (RuntimeException $exception) {
                echo $exception->getMessage();
            }
        PHP;

        $process = new Process([$php, '-d', 'memory_limit=32M', '-d', 'display_errors=1', '-d', 'log_errors=0', '-r', $script]);
        $process->setEnv($environment + [
            'APP_AUTOLOAD' => \dirname(__DIR__, 3).'/vendor/autoload.php',
            'APP_ARCHIVE' => $archive,
        ]);
        $process->run();

        self::assertTrue($process->isSuccessful(), 'The reader died: '.$process->getOutput());
        self::assertSame('Archive page exceeds the safety limit.', $process->getOutput());
    }

    public function testRejectsAnArchiveWithAnUnsafeExpansionRatio(): void
    {
        if ((new ExecutableFinder())->find('7z') === null) self::markTestSkipped('7z is not installed.');
        $this->directory = sys_get_temp_dir().'/comic-7z-test-'.bin2hex(random_bytes(6));
        self::assertTrue(mkdir($this->directory, 0700));
        file_put_contents($this->directory.'/page.jpg', str_repeat("\0", 1_048_576));
        $archive = $this->directory.'/comic.cb7';

        (new Process(['7z', 'a', '-t7z', $archive, 'page.jpg'], $this->directory))->mustRun();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('unsafe compression ratio');

        (new SevenZipPageProvider())->inspect($archive, ComicSourceType::CB7);
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
