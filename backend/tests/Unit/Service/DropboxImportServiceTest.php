<?php

namespace App\Tests\Unit\Service;

use App\Service\ComicService;
use App\Service\ComicFormatService;
use App\Entity\ComicFormatConfiguration;
use App\Service\DropboxImportService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class DropboxImportServiceTest extends TestCase
{
    private function service(string $appFolder = '/Apps/StarbugStoneComics'): DropboxImportService
    {
        $formatEntityManager = $this->createMock(EntityManagerInterface::class);
        $formatEntityManager->method('find')->willReturn(new ComicFormatConfiguration());

        return new DropboxImportService(
            $this->createMock(ComicService::class),
            $this->createMock(EntityManagerInterface::class),
            $this->createMock(HttpClientInterface::class),
            new NullLogger(),
            new ComicFormatService($formatEntityManager),
            $appFolder
        );
    }

    /**
     * Regression: duplicate detection used to compare the Dropbox filename with
     * the stored filename, which ComicService rewrites to "{slug}-{uniqid}.cbz".
     * The two could never match, so every sync re-imported the whole library.
     */
    public function testRecognisesAFileAlreadyImportedFromTheSamePath(): void
    {
        $file = ['path' => '/Apps/StarbugStoneComics/Batman 01.cbz', 'name' => 'Batman 01.cbz'];
        $index = ['paths' => ['/apps/starbugstonecomics/batman 01.cbz' => true], 'titles' => []];

        self::assertTrue($this->service()->isImported($file, $index));
    }

    public function testTreatsAnUnknownPathAsNotImported(): void
    {
        $file = ['path' => '/Apps/StarbugStoneComics/Batman 02.cbz', 'name' => 'Batman 02.cbz'];
        $index = ['paths' => ['/apps/starbugstonecomics/batman 01.cbz' => true], 'titles' => []];

        self::assertFalse($this->service()->isImported($file, $index));
    }

    /**
     * Comics imported before dropbox_path existed have no recorded path, so the
     * derived title is used to avoid re-importing them after an upgrade.
     */
    public function testFallsBackToTheDerivedTitleForLegacyImports(): void
    {
        $file = ['path' => '/Apps/StarbugStoneComics/the_dark-knight.cbz', 'name' => 'the_dark-knight.cbz'];
        $index = ['paths' => [], 'titles' => ['the dark knight' => true]];

        self::assertTrue($this->service()->isImported($file, $index));
    }

    /**
     * Distinct issues of the same series must stay distinct. The old
     * similar_text() check scored these above its 85% threshold and wrongly
     * reported unimported files as already synced.
     */
    public function testDoesNotConfuseNeighbouringIssuesOfTheSameSeries(): void
    {
        $file = ['path' => '/Apps/StarbugStoneComics/Batman 02.cbz', 'name' => 'Batman 02.cbz'];
        $index = ['paths' => [], 'titles' => ['batman 01' => true]];

        self::assertFalse($this->service()->isImported($file, $index));
    }

    /**
     * @dataProvider titleProvider
     */
    public function testDerivesAReadableTitleFromTheFilename(string $filename, string $expected): void
    {
        self::assertSame($expected, $this->service()->titleFromFilename($filename));
    }

    public function titleProvider(): iterable
    {
        yield 'underscores and hyphens' => ['the_dark-knight.cbz', 'The Dark Knight'];
        yield 'already readable' => ['Amazing Spider Man 12.cbz', 'Amazing Spider Man 12'];
        yield 'collapses whitespace' => ['spaced__out--title.cbz', 'Spaced Out Title'];
    }

    /**
     * @dataProvider fileSizeProvider
     */
    public function testFormatsFileSizes(int $bytes, string $expected): void
    {
        self::assertSame($expected, $this->service()->formatFileSize($bytes));
    }

    public function fileSizeProvider(): iterable
    {
        yield 'zero' => [0, '0 B'];
        yield 'bytes' => [512, '512 B'];
        yield 'kilobytes' => [1536, '1.5 KB'];
        yield 'megabytes' => [1572864, '1.5 MB'];
    }
}
