<?php

namespace App\Tests\Unit\Service;

use App\Entity\Comic;
use App\Entity\ComicFormatConfiguration;
use App\Entity\User;
use App\Service\ComicFormatService;
use App\Service\ComicService;
use App\Service\DropboxImportService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Spatie\Dropbox\Client as DropboxClient;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class DropboxImportServiceTest extends TestCase
{
    /**
     * @param list<array{dropboxPath: ?string, title: ?string}> $importedRows
     */
    private function service(
        string $appFolder = '/Apps/StarbugStoneComics',
        ?ComicService $comicService = null,
        array $importedRows = [],
        ?HttpClientInterface $http = null,
        ?EntityManagerInterface $entityManager = null,
    ): DropboxImportService {
        $formatEntityManager = $this->createMock(EntityManagerInterface::class);
        $formatEntityManager->method('find')->willReturn(new ComicFormatConfiguration());

        return new DropboxImportService(
            $comicService ?? $this->createMock(ComicService::class),
            $entityManager ?? $this->entityManagerWithImportedIndex($importedRows),
            $http ?? $this->createMock(HttpClientInterface::class),
            new NullLogger(),
            new ComicFormatService($formatEntityManager),
            $appFolder
        );
    }

    /**
     * @param list<array{dropboxPath: ?string, title: ?string}> $rows
     */
    private function entityManagerWithImportedIndex(array $rows): EntityManagerInterface
    {
        // Query, not AbstractQuery: QueryBuilder::getQuery() declares the
        // concrete return type, and a mock of the parent does not satisfy it.
        $query = $this->createMock(Query::class);
        $query->method('getArrayResult')->willReturn($rows);

        $builder = $this->createMock(QueryBuilder::class);
        $builder->method('select')->willReturnSelf();
        $builder->method('from')->willReturnSelf();
        $builder->method('where')->willReturnSelf();
        $builder->method('setParameter')->willReturnSelf();
        $builder->method('getQuery')->willReturn($query);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('createQueryBuilder')->willReturn($builder);
        $entityManager->method('isOpen')->willReturn(true);

        return $entityManager;
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

    public function testListsEnabledComicSourcesAndTurnsFoldersIntoTags(): void
    {
        $client = $this->createMock(DropboxClient::class);
        $client->method('listFolder')->willReturnCallback(function (string $path): array {
            return match ($path) {
                '/Apps/StarbugStoneComics' => [
                    'entries' => [
                        ['.tag' => 'folder', 'name' => 'Manga', 'path_display' => '/Apps/StarbugStoneComics/Manga'],
                        ['.tag' => 'file', 'name' => 'notes.txt', 'path_display' => '/Apps/StarbugStoneComics/notes.txt', 'size' => 12],
                    ],
                    'has_more' => false,
                ],
                '/Apps/StarbugStoneComics/Manga' => [
                    'entries' => [
                        [
                            '.tag' => 'file',
                            'name' => 'spaceOpera.cbz',
                            'path_display' => '/Apps/StarbugStoneComics/Manga/spaceOpera.cbz',
                            'size' => 2048,
                            'client_modified' => '2026-01-02T00:00:00Z',
                        ],
                    ],
                    'has_more' => false,
                ],
                default => throw new \LogicException('Unexpected Dropbox path: '.$path),
            };
        });

        $files = $this->service()->listComicSourceFiles($client);

        self::assertCount(1, $files);
        self::assertSame('spaceOpera.cbz', $files[0]['name']);
        self::assertSame(['Manga'], $files[0]['tags']);
        self::assertSame(2048, $files[0]['size']);
    }

    public function testContinuesPastTheFirstDropboxPage(): void
    {
        $client = $this->createMock(DropboxClient::class);
        $client->method('listFolder')->willReturn([
            'entries' => [[
                '.tag' => 'file',
                'name' => 'first.cbz',
                'path_display' => '/Apps/StarbugStoneComics/first.cbz',
                'size' => 1,
            ]],
            'has_more' => true,
            'cursor' => 'page-2',
        ]);
        $client->method('listFolderContinue')->with('page-2')->willReturn([
            'entries' => [[
                '.tag' => 'file',
                'name' => 'second.cbz',
                'path_display' => '/Apps/StarbugStoneComics/second.cbz',
                'size' => 2,
            ]],
            'has_more' => false,
        ]);

        $files = $this->service()->listComicSourceFiles($client);

        self::assertSame(['first.cbz', 'second.cbz'], array_column($files, 'name'));
    }

    public function testListingFailuresAreNotSwallowedAsAnEmptyFolder(): void
    {
        $client = $this->createMock(DropboxClient::class);
        $client->method('listFolder')->willThrowException(new \RuntimeException('Dropbox down'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Dropbox down');
        $this->service()->listComicSourceFiles($client);
    }

    public function testSyncUserDryRunCountsNewFilesWithoutImportingThem(): void
    {
        $client = $this->createMock(DropboxClient::class);
        $client->method('listFolder')->willReturn([
            'entries' => [[
                '.tag' => 'file',
                'name' => 'new.cbz',
                'path_display' => '/Apps/StarbugStoneComics/new.cbz',
                'size' => 10,
            ]],
            'has_more' => false,
        ]);

        $comicService = $this->createMock(ComicService::class);
        $comicService->expects(self::never())->method('uploadComic');

        $events = [];
        $result = $this->service(comicService: $comicService)->syncUser(
            $client,
            $this->createMock(User::class),
            10,
            true,
            static function (string $event, array $context) use (&$events): void {
                $events[] = $event;
            }
        );

        self::assertSame(['newFiles' => 1, 'failed' => 0], $result);
        self::assertContains('listed', $events);
        self::assertContains('importing', $events);
    }

    public function testSyncUserSkipsFilesAlreadyImportedAndStopsAtTheLimit(): void
    {
        $client = $this->createMock(DropboxClient::class);
        $client->method('listFolder')->willReturn([
            'entries' => [
                ['.tag' => 'file', 'name' => 'old.cbz', 'path_display' => '/Apps/StarbugStoneComics/old.cbz', 'size' => 1],
                ['.tag' => 'file', 'name' => 'a.cbz', 'path_display' => '/Apps/StarbugStoneComics/a.cbz', 'size' => 1],
                ['.tag' => 'file', 'name' => 'b.cbz', 'path_display' => '/Apps/StarbugStoneComics/b.cbz', 'size' => 1],
            ],
            'has_more' => false,
        ]);

        $events = [];
        $result = $this->service(importedRows: [
            ['dropboxPath' => '/Apps/StarbugStoneComics/old.cbz', 'title' => 'Old'],
        ])->syncUser(
            $client,
            $this->createMock(User::class),
            1,
            true,
            static function (string $event, array $context) use (&$events): void {
                $events[] = [$event, $context['file']['name'] ?? null];
            }
        );

        self::assertSame(1, $result['newFiles']);
        self::assertContains(['skipped', 'old.cbz'], $events);
        self::assertContains(['limitReached', null], $events);
    }

    public function testImportDownloadsThroughATemporaryLinkAndRecordsTheDropboxPath(): void
    {
        $client = $this->createMock(DropboxClient::class);
        $client->method('getTemporaryLink')->willReturn('https://dl.dropbox.test/file');
        // The temporary link is the path under test; falling back to the API
        // endpoint would mean the link download quietly failed.
        $client->expects(self::never())->method('download');

        $requestedUrls = [];
        $http = new MockHttpClient(function (string $method, string $url) use (&$requestedUrls): MockResponse {
            $requestedUrls[] = $url;

            return new MockResponse('cbz-bytes');
        });

        $comic = new Comic();
        $staged = null;
        $comicService = $this->createMock(ComicService::class);
        $comicService->expects(self::once())
            ->method('uploadComic')
            ->willReturnCallback(function (UploadedFile $file, ...$rest) use ($comic, &$staged): Comic {
                $staged = file_get_contents($file->getPathname());

                return $comic;
            });

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('flush');

        $imported = $this->service(
            comicService: $comicService,
            http: $http,
            entityManager: $entityManager,
        )->import($client, $this->createMock(User::class), [
            'path' => '/Apps/StarbugStoneComics/Batman.cbz',
            'name' => 'Batman.cbz',
            'tags' => ['Manga'],
        ]);

        self::assertSame(['https://dl.dropbox.test/file'], $requestedUrls);
        self::assertSame('cbz-bytes', $staged);
        self::assertSame('/Apps/StarbugStoneComics/Batman.cbz', $imported->getDropboxPath());
    }
}
