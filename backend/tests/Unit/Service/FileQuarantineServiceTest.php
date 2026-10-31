<?php

namespace App\Tests\Unit\Service;

use App\Service\FileQuarantineService;
use PHPUnit\Framework\TestCase;

class FileQuarantineServiceTest extends TestCase
{
    private string $temporaryRoot;

    protected function setUp(): void
    {
        $this->temporaryRoot = sys_get_temp_dir() . '/panel-page-flip-' . bin2hex(random_bytes(6));
        mkdir($this->temporaryRoot . '/uploads/7', 0770, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->temporaryRoot);
    }

    public function testQuarantinedFileCanBeRestored(): void
    {
        $source = $this->temporaryRoot . '/uploads/7/example.cbz';
        file_put_contents($source, 'comic data');
        $service = $this->createService();

        $records = $service->quarantine([$source]);

        self::assertFileDoesNotExist($source);
        self::assertCount(1, $records);
        self::assertFileExists($records[0]['quarantinePath']);

        $service->restore($records);
        self::assertSame('comic data', file_get_contents($source));
        self::assertFileDoesNotExist($records[0]['quarantinePath']);
    }

    public function testFileOutsideManagedDirectoryIsRejected(): void
    {
        $outside = $this->temporaryRoot . '/outside.cbz';
        file_put_contents($outside, 'do not move');

        $this->expectException(\RuntimeException::class);
        $this->createService()->quarantine([$outside]);

        self::assertFileExists($outside);
    }

    public function testAFileKeepsItsWholeRetentionWindowHoweverOldItIs(): void
    {
        $source = $this->temporaryRoot . '/uploads/7/example.cbz';
        file_put_contents($source, 'comic data');
        // Uploaded long ago: a move keeps this, so it must not count as the file's age.
        touch($source, strtotime('-2 years'));
        $service = $this->createService();

        $records = $service->quarantine([$source]);
        $endOfWindow = (new \DateTimeImmutable('tomorrow'))->modify(sprintf('+%d days -1 minute', FileQuarantineService::RETENTION_DAYS));

        self::assertSame(['deleted' => 0, 'remaining' => 0], $service->removeExpired($endOfWindow));
        self::assertFileExists($records[0]['quarantinePath']);

        self::assertSame(['deleted' => 1, 'remaining' => 0], $service->removeExpired($endOfWindow->modify('+1 minute')));
        self::assertFileDoesNotExist($records[0]['quarantinePath']);
    }

    /** What production already holds: files from before any of this existed. */
    public function testExistingBatchesPastRetentionAreRemovedWithTheirDirectories(): void
    {
        $old = $this->temporaryRoot . '/quarantine/2026-01-02/abc123/7/covers/9';
        mkdir($old, 0770, true);
        file_put_contents($old . '/cover.jpg', 'cover');
        file_put_contents($this->temporaryRoot . '/quarantine/2026-01-02/abc123/7/comic.cbz', 'comic');
        $recent = $this->temporaryRoot . '/quarantine/2026-10-29/def456/7';
        mkdir($recent, 0770, true);
        file_put_contents($recent . '/comic.cbz', 'recent');

        $result = $this->createService()->removeExpired(new \DateTimeImmutable('2026-10-30 12:00:00'));

        self::assertSame(['deleted' => 2, 'remaining' => 0], $result);
        self::assertDirectoryDoesNotExist($this->temporaryRoot . '/quarantine/2026-01-02');
        self::assertFileExists($recent . '/comic.cbz');
    }

    public function testAFileOutsideTheBatchLayoutIsAgedByItsOwnTimestamp(): void
    {
        mkdir($this->temporaryRoot . '/quarantine/stray', 0770, true);
        file_put_contents($this->temporaryRoot . '/quarantine/stray/old.cbz', 'old');
        touch($this->temporaryRoot . '/quarantine/stray/old.cbz', strtotime('-30 days'));
        file_put_contents($this->temporaryRoot . '/quarantine/stray/new.cbz', 'new');

        $result = $this->createService()->removeExpired();

        self::assertSame(['deleted' => 1, 'remaining' => 0], $result);
        self::assertFileDoesNotExist($this->temporaryRoot . '/quarantine/stray/old.cbz');
        self::assertFileExists($this->temporaryRoot . '/quarantine/stray/new.cbz');
    }

    /** A purged comic leaves empty directories whose names hold its ids. */
    public function testEmptyDirectoriesLeftByAPurgeAreRemovedOnceTheyAreNotNew(): void
    {
        $directory = date('Y-m-d') . '/abc123/7';
        mkdir($this->temporaryRoot . '/quarantine/' . $directory, 0770, true);
        $service = $this->createService();

        $service->removeExpired();
        self::assertDirectoryExists($this->temporaryRoot . '/quarantine/' . $directory, 'quarantine() may be about to use a new directory.');

        $service->removeExpired(new \DateTimeImmutable('+2 hours'));
        self::assertDirectoryDoesNotExist($this->temporaryRoot . '/quarantine/' . $directory);
    }

    public function testAFileThatCannotBeRemovedIsReportedForALaterRun(): void
    {
        $directory = $this->temporaryRoot . '/quarantine/2026-01-02/abc123';
        mkdir($directory, 0770, true);
        file_put_contents($directory . '/comic.cbz', 'comic');
        chmod($directory, 0550);

        try {
            $result = $this->createService()->removeExpired(new \DateTimeImmutable('2026-10-30'));
        } finally {
            chmod($directory, 0770);
        }

        self::assertSame(['deleted' => 0, 'remaining' => 1], $result);
        self::assertFileExists($directory . '/comic.cbz');
    }

    /**
     * The sweep runs from cron, usually as a different user in the same group, and
     * removing a file takes write permission on its directory.
     */
    public function testQuarantineDirectoriesAreGroupWritableWhateverTheUmask(): void
    {
        $source = $this->temporaryRoot . '/uploads/7/example.cbz';
        file_put_contents($source, 'comic data');
        $previous = umask(0022);

        try {
            $records = $this->createService()->quarantine([$source]);
        } finally {
            umask($previous);
        }

        self::assertSame(0770, fileperms(dirname($records[0]['quarantinePath'])) & 0777);
    }

    private function createService(): FileQuarantineService
    {
        return new FileQuarantineService(
            $this->temporaryRoot . '/uploads',
            $this->temporaryRoot . '/quarantine'
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
