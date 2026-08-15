<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Tests\Functional\AbstractApiTestCase;

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
