<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\Comic;
use App\Entity\User;
use App\Service\ComicCleanupService;
use App\Service\FileQuarantineService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;

final class ComicCleanupServiceTest extends TestCase
{
    private string $comicsDirectory;

    protected function setUp(): void
    {
        $this->comicsDirectory = sys_get_temp_dir() . '/ppf-cleanup-' . bin2hex(random_bytes(6));
        mkdir($this->comicsDirectory . '/7', 0777, true);
        mkdir($this->comicsDirectory . '/covers/3', 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->comicsDirectory);
    }

    public function testMissingComicsDirectoryIsReportedRatherThanScanned(): void
    {
        $scan = $this->service([], $this->comicsDirectory . '/missing')->scan();

        self::assertSame([], $scan['orphanedComics']);
        self::assertArrayHasKey('error', $scan);
        self::assertStringContainsString('does not exist', $scan['error']);
    }

    public function testScanKeepsFilesTheDatabaseStillPointsAtAndListsTheRest(): void
    {
        $owner = $this->createMock(User::class);
        $owner->method('getId')->willReturn(7);

        $kept = $this->createMock(Comic::class);
        $kept->method('getFilePath')->willReturn('kept.cbz');
        $kept->method('getOwner')->willReturn($owner);
        $kept->method('getCoverImagePath')->willReturn('cover.jpg');
        $kept->method('getId')->willReturn(3);

        file_put_contents($this->comicsDirectory . '/7/kept.cbz', 'owned');
        file_put_contents($this->comicsDirectory . '/7/orphan.cbz', 'lost');
        file_put_contents($this->comicsDirectory . '/covers/3/cover.jpg', 'cover');
        file_put_contents($this->comicsDirectory . '/covers/3/stray.jpg', 'stray');
        file_put_contents($this->comicsDirectory . '/root-orphan.cbz', 'root');

        $scan = $this->service([$kept])->scan();

        $orphanNames = array_column($scan['orphanedComics'], 'filename');
        sort($orphanNames);
        self::assertSame(['orphan.cbz', 'root-orphan.cbz'], $orphanNames);
        self::assertSame(['stray.jpg'], array_column($scan['orphanedCovers'], 'filename'));
        self::assertSame(2, $scan['totals']['orphanedComics']);
        self::assertSame(1, $scan['totals']['orphanedCovers']);
    }

    public function testApplyQuarantinesTheOrphansTheScanFound(): void
    {
        file_put_contents($this->comicsDirectory . '/orphan.cbz', 'lost');

        // Comics and covers are swept in two separate calls, so the cover sweep
        // still happens (with nothing to do) when only a comic is orphaned.
        $batches = [];
        $quarantine = $this->createMock(FileQuarantineService::class);
        $quarantine->expects(self::exactly(2))
            ->method('quarantine')
            ->willReturnCallback(function (array $paths) use (&$batches): array {
                $batches[] = $paths;

                return array_map(
                    static fn (string $path): array => ['originalPath' => $path, 'quarantinePath' => '/tmp/q'],
                    $paths
                );
            });

        $result = $this->service([], $this->comicsDirectory, $quarantine)->apply();

        self::assertSame(1, $result['quarantined']['orphanedComics']);
        self::assertSame(0, $result['quarantined']['orphanedCovers']);

        [$comicPaths, $coverPaths] = $batches;
        self::assertCount(1, $comicPaths);
        self::assertStringEndsWith('orphan.cbz', $comicPaths[0]);
        self::assertSame([], $coverPaths);
    }

    /**
     * @param list<Comic> $comics
     */
    private function service(
        array $comics,
        ?string $directory = null,
        ?FileQuarantineService $quarantine = null
    ): ComicCleanupService {
        $repository = $this->createMock(EntityRepository::class);
        $repository->method('findAll')->willReturn($comics);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturn($repository);

        return new ComicCleanupService(
            $entityManager,
            $quarantine ?? $this->createMock(FileQuarantineService::class),
            $directory ?? $this->comicsDirectory
        );
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($directory);
    }
}
