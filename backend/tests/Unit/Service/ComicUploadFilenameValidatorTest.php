<?php

namespace App\Tests\Unit\Service;

use App\Service\ComicUploadFilenameValidator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

final class ComicUploadFilenameValidatorTest extends TestCase
{
    /**
     * @dataProvider validFilenameProvider
     */
    public function testAcceptsSafeOriginalFilenames(string $filename): void
    {
        self::assertSame($filename, (new ComicUploadFilenameValidator())->validate($filename));
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
    public function testRejectsUnsafeOriginalFilenames(string $filename): void
    {
        $this->expectException(BadRequestHttpException::class);
        $this->expectExceptionMessage('Invalid filename.');

        (new ComicUploadFilenameValidator())->validate($filename);
    }

    public function invalidFilenameProvider(): iterable
    {
        yield 'forward-slash traversal' => ['../comic.cbz'];
        yield 'backslash traversal' => ['..\\comic.cbz'];
        yield 'control character' => ["comic\0name.cbz"];
        yield 'wrong extension' => ['comic.zip'];
        yield 'excessive byte length' => [str_repeat('a', 252) . '.cbz'];
    }
}
