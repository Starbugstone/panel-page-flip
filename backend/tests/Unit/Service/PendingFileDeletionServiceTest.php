<?php

namespace App\Tests\Unit\Service;

use App\Service\PendingFileDeletionService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class PendingFileDeletionServiceTest extends TestCase
{
    private string $temporaryRoot;

    protected function setUp(): void
    {
        $this->temporaryRoot = sys_get_temp_dir() . '/panel-page-flip-pending-' . bin2hex(random_bytes(6));
        mkdir($this->temporaryRoot . '/managed', 0770, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->temporaryRoot);
    }

    public function testQueuedFileIsPurgedAndRecordIsRemoved(): void
    {
        $path = $this->temporaryRoot . '/managed/personal-data.cbz';
        file_put_contents($path, 'personal data');
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('persist');
        $entityManager->expects(self::once())->method('remove');
        $entityManager->expects(self::once())->method('flush');
        $service = $this->createService($entityManager);

        $records = $service->queue([$path]);
        self::assertCount(1, $records);

        $result = $service->purge($records);

        self::assertSame(['deleted' => 1, 'remaining' => 0], $result);
        self::assertFileDoesNotExist($path);
    }

    public function testFailedDeletionRemainsQueuedForRetry(): void
    {
        $path = $this->temporaryRoot . '/managed/personal-data.cbz';
        mkdir($path);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('persist');
        $entityManager->expects(self::once())->method('remove');
        $entityManager->expects(self::exactly(2))->method('flush');
        $service = $this->createService($entityManager);
        $records = $service->queue([$path]);

        self::assertSame(['deleted' => 0, 'remaining' => 1], $service->purge($records));
        self::assertSame(1, $records[0]->getAttempts());

        rmdir($path);
        file_put_contents($path, 'personal data');
        self::assertSame(['deleted' => 1, 'remaining' => 0], $service->purge($records));
        self::assertFileDoesNotExist($path);
    }

    public function testAlreadyMissingFileCompletesDeletion(): void
    {
        $path = $this->temporaryRoot . '/managed/personal-data.cbz';
        file_put_contents($path, 'personal data');
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('persist');
        $entityManager->expects(self::once())->method('remove');
        $entityManager->expects(self::once())->method('flush');
        $service = $this->createService($entityManager);
        $records = $service->queue([$path]);
        unlink($path);

        self::assertSame(['deleted' => 1, 'remaining' => 0], $service->purge($records));
    }

    public function testPathOutsideManagedRootsIsRejected(): void
    {
        $outsidePath = $this->temporaryRoot . '/outside.cbz';
        file_put_contents($outsidePath, 'outside data');
        $this->expectException(\RuntimeException::class);

        $this->createService($this->createMock(EntityManagerInterface::class))->queue([$outsidePath]);
    }

    public function testMissingManagedFileIsStillQueued(): void
    {
        $path = $this->temporaryRoot . '/managed/already-gone.cbz';
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('persist');
        $entityManager->expects(self::once())->method('remove');
        $entityManager->expects(self::once())->method('flush');
        $service = $this->createService($entityManager);

        $records = $service->queue([$path]);
        self::assertCount(1, $records);
        self::assertSame(['deleted' => 1, 'remaining' => 0], $service->purge($records));
    }

    private function createService(EntityManagerInterface $entityManager): PendingFileDeletionService
    {
        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManager')->willReturn($entityManager);

        return new PendingFileDeletionService(
            $registry,
            [$this->temporaryRoot . '/managed'],
            new NullLogger(),
        );
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($directory);
    }
}
