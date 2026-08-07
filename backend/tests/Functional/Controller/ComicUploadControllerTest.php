<?php

namespace App\Tests\Functional\Controller;

use App\Tests\Functional\AbstractApiTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class ComicUploadControllerTest extends AbstractApiTestCase
{
    /** @var list<string> */
    private array $temporaryUploadDirectories = [];

    /**
     * @dataProvider validFilenameProvider
     */
    public function testUploadInitializationAcceptsSafeOriginalFilenames(string $filename): void
    {
        $user = $this->createAndLoginUser();
        $fileId = 'upload-filename-test';
        $this->temporaryUploadDirectories[] = sys_get_temp_dir()
            . '/comic_uploads/' . $user->getId() . '/' . $fileId;

        $payload = $this->postJson('/api/comics/upload/init', [
            'fileId' => $fileId,
            'filename' => $filename,
            'totalChunks' => 1,
            'metadata' => ['title' => 'Filename test'],
        ]);

        self::assertResponseIsSuccessful();
        self::assertSame($fileId, $payload['fileId']);
    }

    public function validFilenameProvider(): iterable
    {
        yield 'ASCII spaces' => ['My Comic Issue 1.cbz'];
        yield 'non-breaking space' => ["My\u{00A0}Comic.cbz"];
        yield 'Unicode and punctuation' => ['L’épisode (2026) – numéro 1.cbz'];
    }

    /**
     * @dataProvider invalidFilenameProvider
     */
    public function testUploadInitializationRejectsUnsafeOriginalFilenames(string $filename): void
    {
        $user = $this->createAndLoginUser();
        $fileId = 'upload-invalid-test';
        $this->temporaryUploadDirectories[] = sys_get_temp_dir()
            . '/comic_uploads/' . $user->getId() . '/' . $fileId;

        $payload = $this->postJson('/api/comics/upload/init', [
            'fileId' => $fileId,
            'filename' => $filename,
            'totalChunks' => 1,
            'metadata' => ['title' => 'Filename test'],
        ]);

        self::assertResponseStatusCodeSame(400);
        self::assertSame('Invalid filename.', $payload['message']);
    }

    public function invalidFilenameProvider(): iterable
    {
        yield 'forward-slash traversal' => ['../comic.cbz'];
        yield 'backslash traversal' => ['..\\comic.cbz'];
        yield 'control character' => ["comic\0name.cbz"];
        yield 'wrong extension' => ['comic.zip'];
        yield 'excessive byte length' => [str_repeat('a', 252) . '.cbz'];
    }

    /**
     * Staging is bounded while it fills, not once it is full.
     *
     * The per-chunk and per-file limits together used to leave the staging area
     * unbounded: an upload initialised for the maximum chunk count could send
     * every chunk at the maximum size, and only /upload/complete would object —
     * after all of it had been written to disk. Nothing staged counts against
     * the user's quota, so the space was free.
     */
    public function testChunkThatWouldExceedTheTotalSizeIsRefused(): void
    {
        $user = $this->createAndLoginUser();
        $fileId = 'upload-oversize-test';
        $chunkDirectory = sys_get_temp_dir() . '/comic_uploads/' . $user->getId() . '/' . $fileId;
        $this->temporaryUploadDirectories[] = $chunkDirectory;

        $this->postJson('/api/comics/upload/init', [
            'fileId' => $fileId,
            'filename' => 'oversize.cbz',
            'totalChunks' => 3,
            'metadata' => ['title' => 'Oversize test'],
        ]);
        self::assertResponseIsSuccessful();

        $maxTotalBytes = (int) self::getContainer()->getParameter('upload_max_total_bytes');

        // Claim almost the whole allowance without writing it, then send a chunk
        // that cannot fit in what is left.
        $this->writeStagedChunkSizes($chunkDirectory, [0 => $maxTotalBytes - 8]);

        $this->uploadChunk($fileId, 1, str_repeat('X', 64));

        self::assertResponseStatusCodeSame(413);
        self::assertFileDoesNotExist($chunkDirectory . '/chunk_1');
    }

    public function testResendingAChunkDoesNotCountTwiceTowardsTheTotal(): void
    {
        $user = $this->createAndLoginUser();
        $fileId = 'upload-resend-test';
        $chunkDirectory = sys_get_temp_dir() . '/comic_uploads/' . $user->getId() . '/' . $fileId;
        $this->temporaryUploadDirectories[] = $chunkDirectory;

        $this->postJson('/api/comics/upload/init', [
            'fileId' => $fileId,
            'filename' => 'resend.cbz',
            'totalChunks' => 2,
            'metadata' => ['title' => 'Resend test'],
        ]);
        self::assertResponseIsSuccessful();

        $maxTotalBytes = (int) self::getContainer()->getParameter('upload_max_total_bytes');
        // Chunk 0 already fills the allowance. Sending it *again* replaces it
        // rather than adding to it, so a retry after a dropped connection must
        // still be accepted.
        $this->writeStagedChunkSizes($chunkDirectory, [0 => $maxTotalBytes]);

        $this->uploadChunk($fileId, 0, str_repeat('X', 64));

        self::assertResponseIsSuccessful();
    }

    private function uploadChunk(string $fileId, int $chunkIndex, string $contents): void
    {
        $chunkPath = tempnam(sys_get_temp_dir(), 'chunk_');
        file_put_contents($chunkPath, $contents);

        $this->browser()->request(
            'POST',
            '/api/comics/upload/chunk',
            ['fileId' => $fileId, 'chunkIndex' => (string) $chunkIndex],
            ['chunk' => new UploadedFile($chunkPath, 'chunk', 'application/octet-stream', null, true)],
            array_merge(['HTTP_ACCEPT' => 'application/json'], $this->csrfHeader())
        );

        if (is_file($chunkPath)) {
            unlink($chunkPath);
        }
    }

    /**
     * Rewrite the staged sizes without writing the bytes, so the limit can be
     * reached in a test without putting half a gigabyte on the disk.
     *
     * @param array<int, int> $sizes
     */
    private function writeStagedChunkSizes(string $chunkDirectory, array $sizes): void
    {
        $metadataPath = $chunkDirectory . '/metadata.json';
        $metadata = json_decode((string) file_get_contents($metadataPath), true);
        foreach ($sizes as $index => $size) {
            $metadata['receivedChunks'][] = $index;
            $metadata['chunkSizes'][(string) $index] = $size;
        }
        file_put_contents($metadataPath, json_encode($metadata));
    }

    protected function tearDown(): void
    {
        foreach ($this->temporaryUploadDirectories as $directory) {
            if (!is_dir($directory)) {
                continue;
            }

            foreach (glob($directory . '/*') ?: [] as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
            rmdir($directory);
        }

        parent::tearDown();
    }
}
