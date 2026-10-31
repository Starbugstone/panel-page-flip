<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\ComicSource\Pdf\PngWriter;
use App\ComicSource\PageResult;
use App\Service\ComicPageDelivery;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;

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

    /**
     * A few kilobytes of PNG can declare more pixels than memory_limit allows,
     * and GD running out is fatal: the upload's cleanup never runs and neither
     * does the fallback to the source. It has to be handed over unconverted.
     */
    public function testHandsOverAPageTooLargeToDecodeInTheMemoryLeft(): void
    {
        if (!\function_exists('imagewebp')) self::markTestSkipped('GD has no WebP encoder.');
        $php = (new PhpExecutableFinder())->find();
        if ($php === false) self::markTestSkipped('No PHP binary to run the isolated check with.');

        $path = (string) tempnam(sys_get_temp_dir(), 'comic-page-');
        file_put_contents($path, PngWriter::encode(6000, 5000, 8, PngWriter::COLOR_GRAY, str_repeat("\0", 30_000_000)));

        $script = <<<'PHP'
            require getenv('APP_AUTOLOAD');
            $page = new App\ComicSource\PageResult((string) file_get_contents(getenv('APP_PAGE')), 'image/png');
            echo (new App\Service\ComicPageDelivery())->encode($page, 800, 80) === null ? 'source' : 'converted';
        PHP;

        $process = new Process([$php, '-d', 'memory_limit=64M', '-d', 'display_errors=1', '-d', 'log_errors=0', '-r', $script]);
        $process->setEnv([
            'APP_AUTOLOAD' => \dirname(__DIR__, 3).'/vendor/autoload.php',
            'APP_PAGE' => $path,
        ]);
        $process->run();
        unlink($path);

        self::assertTrue($process->isSuccessful(), 'The conversion died: '.$process->getOutput());
        self::assertSame('source', $process->getOutput());
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
