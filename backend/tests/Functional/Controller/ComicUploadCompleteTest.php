<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Service\ComicService;
use App\Service\ComicUploadRejectedException;
use App\Service\StorageQuotaBusyException;
use App\Service\StorageQuotaExceededException;
use App\Tests\Functional\AbstractApiTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class ComicUploadCompleteTest extends AbstractApiTestCase
{
    /** @var list<string> */
    private array $temporaryUploadDirectories = [];

    public function testCompleteWithoutAFileIdIsRejected(): void
    {
        $this->createAndLoginUser();

        $payload = $this->postJson('/api/comics/upload/complete', []);

        self::assertResponseStatusCodeSame(400);
        self::assertSame('Missing fileId parameter', $payload['message']);
    }

    public function testCompleteForAnUnknownUploadIsRejected(): void
    {
        $this->createAndLoginUser();

        $payload = $this->postJson('/api/comics/upload/complete', ['fileId' => 'never-started']);

        self::assertResponseStatusCodeSame(400);
        self::assertSame('Upload not found', $payload['message']);
    }

    public function testCompleteBeforeEveryChunkArrivesIsRejected(): void
    {
        $user = $this->createAndLoginUser();
        $fileId = 'upload-incomplete';
        $this->temporaryUploadDirectories[] = sys_get_temp_dir()
            . '/comic_uploads/' . $user->getId() . '/' . $fileId;

        $this->postJson('/api/comics/upload/init', [
            'fileId' => $fileId,
            'filename' => 'partial.cbz',
            'totalChunks' => 2,
            'metadata' => ['title' => 'Partial'],
        ]);
        self::assertResponseIsSuccessful();

        $payload = $this->postJson('/api/comics/upload/complete', ['fileId' => $fileId]);

        self::assertResponseStatusCodeSame(400);
        self::assertSame('Not all chunks received', $payload['message']);
        self::assertSame(0, $payload['chunksReceived']);
        self::assertSame(2, $payload['chunksExpected']);
    }

    public function testCreateWithoutAFileIsRejected(): void
    {
        $this->createAndLoginUser();

        $this->browser()->request(
            'POST',
            '/api/comics',
            ['title' => 'No file'],
            [],
            array_merge(['HTTP_ACCEPT' => 'application/json'], $this->csrfHeader())
        );

        self::assertResponseStatusCodeSame(400);
        $payload = json_decode((string) $this->browser()->getResponse()->getContent(), true);
        self::assertSame('No file uploaded', $payload['message']);
    }

    public function testCreateWithoutATitleIsRejected(): void
    {
        $this->createAndLoginUser();
        $path = tempnam(sys_get_temp_dir(), 'comic-');
        file_put_contents($path, 'cbz');

        $this->browser()->request(
            'POST',
            '/api/comics',
            [],
            ['file' => new \Symfony\Component\HttpFoundation\File\UploadedFile($path, 'issue.cbz', 'application/zip', null, true)],
            array_merge(['HTTP_ACCEPT' => 'application/json'], $this->csrfHeader())
        );

        self::assertResponseStatusCodeSame(400);
        $payload = json_decode((string) $this->browser()->getResponse()->getContent(), true);
        self::assertSame('Title is required', $payload['message']);

        if (is_file($path)) {
            unlink($path);
        }
    }

    public function testCreateRejectsMalformedTagJsonBeforeCallingTheUploadService(): void
    {
        $this->createAndLoginUser();
        $comicService = $this->createMock(ComicService::class);
        $comicService->expects(self::never())->method('uploadComic');
        static::getContainer()->set(ComicService::class, $comicService);
        $path = tempnam(sys_get_temp_dir(), 'comic-');
        self::assertIsString($path);
        file_put_contents($path, 'cbz');

        $this->browser()->request(
            'POST',
            '/api/comics',
            ['title' => 'Malformed tags', 'tags' => '{not-json'],
            ['file' => new UploadedFile($path, 'issue.cbz', 'application/zip', null, true)],
            array_merge(['HTTP_ACCEPT' => 'application/json'], $this->csrfHeader())
        );

        self::assertResponseStatusCodeSame(400);
        $payload = json_decode((string) $this->browser()->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('Tags must be a JSON array of strings.', $payload['message']);

        if (is_file($path)) {
            unlink($path);
        }
    }

    public function testCreatePreservesTheVettedMalformedSourceRejectionFromTheUploadService(): void
    {
        $this->createAndLoginUser();

        $payload = $this->uploadSingleComic();

        self::assertResponseStatusCodeSame(400);
        self::assertSame('Uploaded file is not a valid or supported comic source.', $payload['message']);
    }

    public function testCreatePreservesAVettedUploadRejection(): void
    {
        $this->createAndLoginUser();
        $comicService = $this->createMock(ComicService::class);
        $comicService->method('uploadComic')
            ->willThrowException(new ComicUploadRejectedException('Uploaded file is empty.'));
        static::getContainer()->set(ComicService::class, $comicService);

        $payload = $this->uploadSingleComic();

        self::assertResponseStatusCodeSame(400);
        self::assertSame('Uploaded file is empty.', $payload['message']);
    }

    public function testCreateReportsAnInternalUploadFailureWithoutBlamingTheFileOrLeakingDetails(): void
    {
        $this->createAndLoginUser();
        $comicService = $this->createMock(ComicService::class);
        $comicService->method('uploadComic')
            ->willThrowException(new \RuntimeException('Database failed at /srv/private/comics.'));
        static::getContainer()->set(ComicService::class, $comicService);

        $payload = $this->uploadSingleComic();

        self::assertResponseStatusCodeSame(500);
        self::assertSame('Upload failed because of a server error. Please try again later.', $payload['message']);
        self::assertStringNotContainsString('/srv/private', (string) $this->browser()->getResponse()->getContent());
        self::assertStringNotContainsString('format', strtolower($payload['message']));
        self::assertStringNotContainsString('quota', strtolower($payload['message']));
    }

    public function testCreateReportsAnExceededStorageQuotaAsAnUploadRejection(): void
    {
        $this->createAndLoginUser();
        $comicService = $this->createMock(ComicService::class);
        $comicService->method('uploadComic')
            ->willThrowException(new StorageQuotaExceededException('internal quota wording'));
        static::getContainer()->set(ComicService::class, $comicService);

        $payload = $this->uploadSingleComic();

        self::assertResponseStatusCodeSame(413);
        self::assertSame('User storage quota exceeded.', $payload['message']);
        self::assertStringNotContainsString('internal', $payload['message']);
    }

    public function testCreateReportsABusyStorageLockAsRetryable(): void
    {
        $this->createAndLoginUser();
        $comicService = $this->createMock(ComicService::class);
        $comicService->method('uploadComic')
            ->willThrowException(new StorageQuotaBusyException('internal lock wording'));
        static::getContainer()->set(ComicService::class, $comicService);

        $payload = $this->uploadSingleComic();

        self::assertResponseStatusCodeSame(409);
        self::assertSame(
            'Another storage operation is already in progress. Please try again.',
            $payload['message']
        );
        self::assertStringNotContainsString('internal', $payload['message']);
    }

    /** @return array<string, mixed> */
    private function uploadSingleComic(): array
    {
        $path = tempnam(sys_get_temp_dir(), 'comic-');
        file_put_contents($path, 'cbz');

        $this->browser()->request(
            'POST',
            '/api/comics',
            ['title' => 'Runtime failure'],
            ['file' => new UploadedFile($path, 'issue.cbz', 'application/zip', null, true)],
            array_merge(['HTTP_ACCEPT' => 'application/json'], $this->csrfHeader())
        );

        if (is_file($path)) {
            unlink($path);
        }

        return json_decode(
            (string) $this->browser()->getResponse()->getContent(),
            true,
            flags: JSON_THROW_ON_ERROR
        );
    }

    protected function tearDown(): void
    {
        foreach ($this->temporaryUploadDirectories as $directory) {
            if (!is_dir($directory)) {
                continue;
            }
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

        parent::tearDown();
    }
}
