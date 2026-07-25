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
