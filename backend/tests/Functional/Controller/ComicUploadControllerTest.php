<?php

namespace App\Tests\Functional\Controller;

use App\Entity\User;
use App\Tests\Factory\ComicFactory;
use App\Tests\Functional\AbstractApiTestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class ComicUploadControllerTest extends AbstractApiTestCase
{
    /** @var list<string> */
    private array $temporaryUploadDirectories = [];

    /** @var list<string> */
    private array $temporaryComicDirectories = [];

    /**
     * @dataProvider validFilenameProvider
     */
    public function testUploadInitializationAcceptsSafeOriginalFilenames(string $filename): void
    {
        $user = $this->createAndLoginUser();
        $fileId = 'upload-filename-test';
        $this->temporaryUploadDirectories[] = self::getContainer()->getParameter('upload_staging_directory')
            . '/' . $user->getId() . '/' . $fileId;

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
        $this->temporaryUploadDirectories[] = self::getContainer()->getParameter('upload_staging_directory')
            . '/' . $user->getId() . '/' . $fileId;

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

    /** @dataProvider invalidInitializationTypeProvider */
    public function testUploadInitializationRejectsContainerValuesForScalarParameters(array $override): void
    {
        $user = $this->createAndLoginUser();
        $fileId = 'upload-type-test';
        $this->temporaryUploadDirectories[] = self::getContainer()->getParameter('upload_staging_directory')
            . '/' . $user->getId() . '/' . $fileId;

        $payload = $this->postJson('/api/comics/upload/init', array_replace([
            'fileId' => $fileId,
            'filename' => 'types.cbz',
            'totalChunks' => 1,
            'metadata' => ['title' => 'Type validation'],
        ], $override));

        self::assertResponseStatusCodeSame(400);
        self::assertSame('Invalid upload parameter types', $payload['message']);
    }

    public function invalidInitializationTypeProvider(): iterable
    {
        yield 'file ID array' => [['fileId' => ['upload-type-test']]];
        yield 'filename array' => [['filename' => ['types.cbz']]];
        yield 'chunk count array' => [['totalChunks' => [1]]];
        yield 'metadata string' => [['metadata' => 'not-an-object']];
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
        $chunkDirectory = self::getContainer()->getParameter('upload_staging_directory') . '/' . $user->getId() . '/' . $fileId;
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
        $chunkDirectory = self::getContainer()->getParameter('upload_staging_directory') . '/' . $user->getId() . '/' . $fileId;
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

    /** A fixed number of uploads at once, however many files are being offered. */
    public function testInitializationIsRefusedPastTheActiveUploadLimit(): void
    {
        $user = $this->createAndLoginUser();
        $limit = (int) self::getContainer()->getParameter('upload_max_active_uploads');

        for ($i = 0; $i < $limit; ++$i) {
            $this->initUpload($user, 'upload-active-' . $i);
            self::assertResponseIsSuccessful();
        }

        $payload = $this->initUpload($user, 'upload-active-over');

        self::assertResponseStatusCodeSame(429);
        self::assertStringContainsString('Too many uploads', $payload['message']);
        self::assertDirectoryDoesNotExist($this->stagingDirectory($user, 'upload-active-over'));
    }

    public function testAnotherAccountsUploadsDoNotCountAgainstTheLimit(): void
    {
        $user = $this->createAndLoginUser();
        $limit = (int) self::getContainer()->getParameter('upload_max_active_uploads');
        for ($i = 0; $i < $limit; ++$i) {
            $this->initUpload($user, 'upload-other-' . $i);
            self::assertResponseIsSuccessful();
        }

        $other = $this->createAndLoginUser();
        $this->initUpload($other, 'upload-other-account');

        self::assertResponseIsSuccessful();
    }

    /**
     * Each of these fits on its own. It is the two together that would not, and
     * until now neither looked at the other, or at the quota at all.
     */
    public function testParallelUploadsShareTheRemainingQuota(): void
    {
        $user = $this->createAndLoginUser(['storageQuotaOverrideBytes' => 100]);
        $this->initUpload($user, 'upload-parallel-a', 3);
        $this->initUpload($user, 'upload-parallel-b', 3);

        $this->uploadChunk('upload-parallel-a', 0, str_repeat('A', 60));
        self::assertResponseIsSuccessful();
        $this->uploadChunk('upload-parallel-b', 0, str_repeat('B', 30));
        self::assertResponseIsSuccessful();

        $this->uploadChunk('upload-parallel-b', 1, str_repeat('B', 20));

        self::assertResponseStatusCodeSame(413);
        self::assertStringContainsString('quota exceeded', $this->json()['message']);
        // The client gives up on a file at this point and cannot resume it, so its
        // staged bytes must not go on counting against the upload that is left.
        self::assertDirectoryDoesNotExist($this->stagingDirectory($user, 'upload-parallel-b'));

        $this->uploadChunk('upload-parallel-a', 1, str_repeat('A', 40));
        self::assertResponseIsSuccessful();
    }

    public function testResendingAChunkOnlyChargesTheDifferenceAgainstTheQuota(): void
    {
        $user = $this->createAndLoginUser(['storageQuotaOverrideBytes' => 64]);
        $this->initUpload($user, 'upload-quota-resend', 2);

        $this->uploadChunk('upload-quota-resend', 0, str_repeat('X', 64));
        self::assertResponseIsSuccessful();

        $this->uploadChunk('upload-quota-resend', 0, str_repeat('Y', 64));
        self::assertResponseIsSuccessful();
    }

    public function testAnUploadIsRefusedAtInitializationWhenStagedBytesLeaveNoRoom(): void
    {
        $user = $this->createAndLoginUser(['storageQuotaOverrideBytes' => 64]);
        $this->initUpload($user, 'upload-room-a', 2);
        $this->uploadChunk('upload-room-a', 0, str_repeat('X', 64));
        self::assertResponseIsSuccessful();

        $payload = $this->initUpload($user, 'upload-room-b');

        self::assertResponseStatusCodeSame(413);
        self::assertStringContainsString('unfinished uploads', $payload['message']);
    }

    public function testAnAccountAlreadyAtItsQuotaIsRefusedBeforeSendingAnything(): void
    {
        $user = $this->createAndLoginUser(['storageQuotaOverrideBytes' => 64]);
        ComicFactory::new()->ownedBy($user)->create(['fileSize' => 64]);

        $payload = $this->initUpload($user, 'upload-at-quota');

        self::assertResponseStatusCodeSame(413);
        self::assertSame('User storage quota exceeded.', $payload['message']);
    }

    public function testUnlimitedAccountsAreOnlyHeldToTheStagingLimits(): void
    {
        $user = $this->createAndLoginUser(['storageQuotaOverrideBytes' => 0]);
        $this->initUpload($user, 'upload-unlimited', 1);
        self::assertResponseIsSuccessful();

        $this->uploadChunk('upload-unlimited', 0, str_repeat('X', 64));

        self::assertResponseIsSuccessful();
    }

    /** The whole journey, with two files in the air as bulk upload runs them. */
    public function testTwoInterleavedUploadsBothBecomeComics(): void
    {
        $user = $this->createAndLoginUser();
        $parts = str_split($this->comicArchive(), 256);
        self::assertGreaterThan(1, count($parts));

        foreach (['upload-both-a', 'upload-both-b'] as $fileId) {
            $this->initUpload($user, $fileId, count($parts));
            self::assertResponseIsSuccessful();
        }
        foreach ($parts as $index => $part) {
            foreach (['upload-both-a', 'upload-both-b'] as $fileId) {
                $this->uploadChunk($fileId, $index, $part);
                self::assertResponseIsSuccessful();
            }
        }

        foreach (['upload-both-a', 'upload-both-b'] as $fileId) {
            $payload = $this->postJson('/api/comics/upload/complete', ['fileId' => $fileId]);

            self::assertResponseIsSuccessful();
            self::assertSame('Limit test', $payload['comic']['title']);
            self::assertDirectoryDoesNotExist($this->stagingDirectory($user, $fileId));
            $this->temporaryComicDirectories[] = self::getContainer()->getParameter('comics_directory') . '/' . $user->getId();
        }

        self::assertSame(2, ComicFactory::repository()->count(['owner' => $user]));
    }

    /**
     * The next upload should not be refused over the bytes of one the server just
     * turned down at completion, which the client will not try again.
     */
    public function testAnUploadRefusedAtCompletionReleasesItsStagedBytes(): void
    {
        $user = $this->createAndLoginUser(['storageQuotaOverrideBytes' => 64]);
        $this->initUpload($user, 'upload-refused-complete', 1);
        $this->uploadChunk('upload-refused-complete', 0, str_repeat('X', 64));
        self::assertResponseIsSuccessful();
        ComicFactory::new()->ownedBy($user)->create(['fileSize' => 1]);

        $this->postJson('/api/comics/upload/complete', ['fileId' => 'upload-refused-complete']);

        self::assertResponseStatusCodeSame(413);
        self::assertDirectoryDoesNotExist($this->stagingDirectory($user, 'upload-refused-complete'));
    }

    /**
     * An invalid form value must not become chunk zero through PHP's integer
     * cast. Otherwise a malformed or tampered request silently replaces a
     * valid first chunk and the corruption is discovered only after upload.
     */
    public function testNonNumericChunkIndexDoesNotOverwriteChunkZero(): void
    {
        $user = $this->createAndLoginUser();
        $fileId = 'upload-invalid-index-test';
        $chunkDirectory = self::getContainer()->getParameter('upload_staging_directory') . '/' . $user->getId() . '/' . $fileId;
        $this->temporaryUploadDirectories[] = $chunkDirectory;

        $this->postJson('/api/comics/upload/init', [
            'fileId' => $fileId,
            'filename' => 'invalid-index.cbz',
            'totalChunks' => 1,
            'metadata' => ['title' => 'Invalid index test'],
        ]);
        self::assertResponseIsSuccessful();

        $this->uploadChunk($fileId, 0, 'original chunk zero');
        self::assertResponseIsSuccessful();

        $this->uploadChunk($fileId, 'not-a-number', 'replacement payload');

        self::assertResponseStatusCodeSame(400);
        self::assertSame('original chunk zero', file_get_contents($chunkDirectory . '/chunk_0'));
    }

    /**
     * Reusing an active file ID used to reset metadata while leaving its chunk
     * files behind. The next completion then reported missing chunks, and a
     * second upload could mutate the first one's staging area.
     */
    public function testActiveUploadCannotBeReinitialized(): void
    {
        $user = $this->createAndLoginUser();
        $fileId = 'upload-reinitialize-test';
        $chunkDirectory = self::getContainer()->getParameter('upload_staging_directory') . '/' . $user->getId() . '/' . $fileId;
        $this->temporaryUploadDirectories[] = $chunkDirectory;

        $this->postJson('/api/comics/upload/init', [
            'fileId' => $fileId,
            'filename' => 'original.cbz',
            'totalChunks' => 2,
            'metadata' => ['title' => 'Original upload'],
        ]);
        self::assertResponseIsSuccessful();
        $this->uploadChunk($fileId, 0, 'first chunk');
        self::assertResponseIsSuccessful();

        $payload = $this->postJson('/api/comics/upload/init', [
            'fileId' => $fileId,
            'filename' => 'replacement.cbz',
            'totalChunks' => 1,
            'metadata' => ['title' => 'Replacement upload'],
        ]);

        self::assertResponseStatusCodeSame(409);
        self::assertSame('Upload already initialized', $payload['message']);
        $metadata = json_decode((string) file_get_contents($chunkDirectory . '/metadata.json'), true);
        self::assertSame('original.cbz', $metadata['filename']);
        self::assertSame([0], $metadata['receivedChunks']);
        self::assertSame('first chunk', file_get_contents($chunkDirectory . '/chunk_0'));
    }

    /**
     * The client sends five chunks of one upload at once, and the handler is a
     * read-modify-write of a single JSON file. Every chunk has to survive that:
     * a lost chunkSizes entry undercounts the staged total and lets the size
     * limit be walked past, and a lost receivedChunks entry makes
     * /upload/complete reject an upload that did arrive in full.
     */
    public function testEveryChunkIsRecordedInTheUploadMetadata(): void
    {
        $user = $this->createAndLoginUser();
        $fileId = 'upload-metadata-test';
        $chunkDirectory = self::getContainer()->getParameter('upload_staging_directory') . '/' . $user->getId() . '/' . $fileId;
        $this->temporaryUploadDirectories[] = $chunkDirectory;

        $this->postJson('/api/comics/upload/init', [
            'fileId' => $fileId,
            'filename' => 'metadata.cbz',
            'totalChunks' => 5,
            'metadata' => ['title' => 'Metadata test'],
        ]);
        self::assertResponseIsSuccessful();

        for ($chunkIndex = 0; $chunkIndex < 5; $chunkIndex++) {
            $this->uploadChunk($fileId, $chunkIndex, str_repeat('X', 32 + $chunkIndex));
            self::assertResponseIsSuccessful();
        }

        $metadata = json_decode((string) file_get_contents($chunkDirectory . '/metadata.json'), true);
        sort($metadata['receivedChunks']);
        self::assertSame([0, 1, 2, 3, 4], $metadata['receivedChunks']);
        self::assertSame(
            ['0' => 32, '1' => 33, '2' => 34, '3' => 35, '4' => 36],
            $metadata['chunkSizes']
        );
    }

    /**
     * The lock is what makes the check above hold under real concurrency, and it
     * only holds if it is genuinely exclusive and genuinely released.
     *
     * Parallel HTTP requests cannot be driven from this harness, so the lock file
     * itself is asserted: exclusive while held, and free once the request that
     * held it has answered — including a request that was rejected, which is the
     * path that would otherwise wedge the rest of the upload.
     */
    public function testTheChunkLockIsExclusiveAndReleased(): void
    {
        $user = $this->createAndLoginUser();
        $fileId = 'upload-lock-test';
        $chunkDirectory = self::getContainer()->getParameter('upload_staging_directory') . '/' . $user->getId() . '/' . $fileId;
        $this->temporaryUploadDirectories[] = $chunkDirectory;

        $this->postJson('/api/comics/upload/init', [
            'fileId' => $fileId,
            'filename' => 'lock.cbz',
            'totalChunks' => 2,
            'metadata' => ['title' => 'Lock test'],
        ]);
        self::assertResponseIsSuccessful();

        $this->uploadChunk($fileId, 0, str_repeat('X', 16));
        self::assertResponseIsSuccessful();

        $lockPath = $chunkDirectory . '/.chunk.lock';
        self::assertFileExists($lockPath);

        // Released: a request that answered must not still hold it.
        $first = fopen($lockPath, 'c');
        self::assertTrue(flock($first, LOCK_EX | LOCK_NB), 'The chunk lock was left held after a request.');

        // Exclusive: a second holder cannot get in while the first has it.
        $second = fopen($lockPath, 'c');
        self::assertFalse(flock($second, LOCK_EX | LOCK_NB), 'The chunk lock is not exclusive.');

        flock($first, LOCK_UN);
        fclose($first);
        fclose($second);

        // A rejected chunk releases the lock too, so the upload is not wedged.
        $this->uploadChunk($fileId, 99, str_repeat('X', 16));
        self::assertResponseStatusCodeSame(400);

        $afterRejection = fopen($lockPath, 'c');
        self::assertTrue(
            flock($afterRejection, LOCK_EX | LOCK_NB),
            'A rejected chunk left the upload lock held.'
        );
        flock($afterRejection, LOCK_UN);
        fclose($afterRejection);
    }

    /**
     * A chunk for an upload that was never initialised is refused, and told so
     * in as many words.
     *
     * The two handlers resolve the same staging directory and say different
     * things when it is not there — "never started" here, "already gone" at
     * /complete — so the wording is asserted on both sides. Sharing the lookup
     * must not end with a chunk request reporting the other one's message.
     */
    public function testAChunkForAnUninitialisedUploadIsRejected(): void
    {
        $this->createAndLoginUser();

        $this->uploadChunk('never-initialised', 0, 'payload');

        self::assertResponseStatusCodeSame(400);
        self::assertSame(
            'Upload not initialized',
            json_decode((string) $this->browser()->getResponse()->getContent(), true)['message']
        );
    }

    public function testChunkUploadRejectsAContainerFileId(): void
    {
        $this->createAndLoginUser();

        $this->browser()->request(
            'POST',
            '/api/comics/upload/chunk',
            ['fileId' => ['not-a-scalar'], 'chunkIndex' => '0'],
            [],
            array_merge(['HTTP_ACCEPT' => 'application/json'], $this->csrfHeader())
        );

        self::assertResponseStatusCodeSame(400);
        self::assertSame(
            'Invalid fileId.',
            json_decode((string) $this->browser()->getResponse()->getContent(), true)['message']
        );
    }

    /** @return array<string, mixed> */
    private function initUpload(User $user, string $fileId, int $totalChunks = 1): array
    {
        $this->temporaryUploadDirectories[] = $this->stagingDirectory($user, $fileId);

        return $this->postJson('/api/comics/upload/init', [
            'fileId' => $fileId,
            'filename' => 'limit.cbz',
            'totalChunks' => $totalChunks,
            'metadata' => ['title' => 'Limit test'],
        ]);
    }

    private function stagingDirectory(User $user, string $fileId): string
    {
        return self::getContainer()->getParameter('upload_staging_directory') . '/' . $user->getId() . '/' . $fileId;
    }

    private function comicArchive(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'comic-archive-');
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($path, \ZipArchive::OVERWRITE) === true);
        $zip->addFromString('page-01.jpg', base64_decode(
            '/9j/4AAQSkZJRgABAQEAYABgAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8UHRofHh0a'
            . 'HBwgJC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPC4zNDL/wAALCAABAAEBAREA/8QAHwAAAQUBAQEB'
            . 'AQEAAAAAAAAAAAECAwQFBgcICQoL/8QAtRAAAgEDAwIEAwUFBAQAAAF9AQIDAAQRBRIhMUEGE1Fh'
            . 'ByJxFDKBkaEII0KxwRVS0fAkM2JyggkKFhcYGRolJicoKSo0NTY3ODk6Q0RFRkdISUpTVFVWV1hZ'
            . 'WmNkZWZnaGlqc3R1dnd4eXqDhIWGh4iJipKTlJWWl5iZmqKjpKWmp6ipqrKztLW2t7i5usLDxMXG'
            . 'x8jJytLT1NXW19jZ2uHi4+Tl5ufo6erx8vP09fb3+Pn6/9oACAEBAAA/AP/Z'
        ));
        $zip->close();
        $contents = (string) file_get_contents($path);
        unlink($path);

        return $contents;
    }

    private function uploadChunk(string $fileId, int|string $chunkIndex, string $contents): void
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

            // scandir rather than glob: the chunk lock is a dot-file, and glob
            // skips those, so rmdir would fail on a directory that still had it.
            foreach (scandir($directory) ?: [] as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }

                $path = $directory . '/' . $entry;
                if (is_file($path)) {
                    unlink($path);
                }
            }
            rmdir($directory);
        }

        foreach (array_unique($this->temporaryComicDirectories) as $directory) {
            (new Filesystem())->remove($directory);
        }

        parent::tearDown();
    }
}
