<?php

namespace App\Tests\Functional\Controller;

use App\Tests\Functional\AbstractApiTestCase;

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
