<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\ComicSource\PageResult;
use App\Service\ComicPageDelivery;
use PHPUnit\Framework\TestCase;

/**
 * The header-reading half of page delivery.
 *
 * measure() and encode() both start by asking what shape the bytes are, and
 * both have to answer "not an image I can read" without throwing — a comic
 * carrying a stray text file must still produce a working page.
 */
final class ComicPageDeliveryTest extends TestCase
{
    public function testMeasureReadsDimensionsFromAnImageHeader(): void
    {
        $geometry = (new ComicPageDelivery())->measure($this->png(7, 3), 4);

        self::assertNotNull($geometry);
        self::assertSame(7, $geometry->width);
        self::assertSame(3, $geometry->height);
        self::assertSame(4, $geometry->page);
    }

    /**
     * @dataProvider notAnImage
     */
    public function testMeasureRefusesBytesThatAreNotAReadableImage(string $bytes): void
    {
        self::assertNull((new ComicPageDelivery())->measure($bytes, 1));
    }

    /**
     * @dataProvider notAnImageWithBytes
     */
    public function testEncodeRefusesBytesThatAreNotAReadableImage(string $bytes): void
    {
        $source = new PageResult($bytes, 'image/jpeg');

        self::assertNull((new ComicPageDelivery())->encode($source, 800, 80));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function notAnImage(): iterable
    {
        yield 'empty' => [''];

        yield from self::notAnImageWithBytes();
    }

    /**
     * The same cases minus the empty one, which PageResult refuses to wrap at
     * all — so encode() can never be handed it.
     *
     * @return iterable<string, array{string}>
     */
    public static function notAnImageWithBytes(): iterable
    {
        yield 'plain text' => ['not an image at all'];
        // A PNG signature with nothing behind it: enough to look like an image
        // to anything sniffing the first bytes, not enough to have a size.
        yield 'truncated png header' => ["\x89PNG\r\n\x1a\n"];
        // These two parse. A header can be perfectly well formed and still
        // describe nothing, which is why a readable header is not on its own
        // enough to treat the bytes as a page.
        yield 'gif declaring no size' => ['GIF89a'."\x00\x00\x00\x00\x80\x00\x00"];
        yield 'gif declaring no height' => ['GIF89a'."\x0a\x00\x00\x00\x80\x00\x00"];
    }

    private function png(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        self::assertNotFalse($image);

        ob_start();
        imagepng($image);
        $bytes = (string) ob_get_clean();
        imagedestroy($image);

        return $bytes;
    }
}
