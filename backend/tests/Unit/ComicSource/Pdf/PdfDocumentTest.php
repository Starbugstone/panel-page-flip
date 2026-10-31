<?php

namespace App\Tests\Unit\ComicSource\Pdf;

use App\ComicSource\Pdf\PdfDocument;
use App\ComicSource\Pdf\PdfException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * The native reader is what makes PDF work like CBZ: a comic PDF is a container
 * of full-page images, and reading one should not need a renderer, a subprocess
 * or a re-encode any more than opening a CBZ does.
 */
final class PdfDocumentTest extends TestCase
{
    /** A real 32-pixel JPEG, so extraction is checked against actual JPEG bytes. */
    public const TINY_JPEG_BASE64 = '/9j/4AAQSkZJRgABAQEAAgACAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8UHRofHh0aHBwgJC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPC4zNDL/2wBDAQkJCQwLDBgNDRgyIRwhMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjL/wAARCAAgABgDASIAAhEBAxEB/8QAHwAAAQUBAQEBAQEAAAAAAAAAAAECAwQFBgcICQoL/8QAtRAAAgEDAwIEAwUFBAQAAAF9AQIDAAQRBRIhMUEGE1FhByJxFDKBkaEII0KxwRVS0fAkM2JyggkKFhcYGRolJicoKSo0NTY3ODk6Q0RFRkdISUpTVFVWV1hZWmNkZWZnaGlqc3R1dnd4eXqDhIWGh4iJipKTlJWWl5iZmqKjpKWmp6ipqrKztLW2t7i5usLDxMXGx8jJytLT1NXW19jZ2uHi4+Tl5ufo6erx8vP09fb3+Pn6/8QAHwEAAwEBAQEBAQEBAQAAAAAAAAECAwQFBgcICQoL/8QAtREAAgECBAQDBAcFBAQAAQJ3AAECAxEEBSExBhJBUQdhcRMiMoEIFEKRobHBCSMzUvAVYnLRChYkNOEl8RcYGRomJygpKjU2Nzg5OkNERUZHSElKU1RVVldYWVpjZGVmZ2hpanN0dXZ3eHl6goOEhYaHiImKkpOUlZaXmJmaoqOkpaanqKmqsrO0tba3uLm6wsPExcbHyMnK0tPU1dbX2Nna4uPk5ebn6Onq8vP09fb3+Pn6/9oADAMBAAIRAxEAPwD3+iiigAooooAKKKKACiiigD//2Q==';

    private ?string $path = null;

    public function testReadsPageCountAndHandsBackEachPagesOwnImageUntouched(): void
    {
        $jpeg = $this->jpeg();
        $this->write($this->imagePdf([$jpeg, $jpeg, $jpeg]));

        $document = PdfDocument::open($this->path);
        self::assertSame(3, $document->pageCount());

        for ($page = 1; $page <= 3; ++$page) {
            $image = $document->pageImage($page);
            self::assertNotNull($image, "Page $page should be an embedded image.");
            self::assertSame('image/jpeg', $image->mimeType);
            // Byte-identical: the reader gets the author's own JPEG, not a
            // re-encode of it. This is the whole advantage over rasterising.
            self::assertSame($jpeg, $image->content, "Page $page was not returned untouched.");
        }
    }

    /**
     * A page that has to be drawn has no image to hand over. Saying so is what
     * lets the provider fall back to Poppler instead of serving something wrong.
     */
    public function testReportsNoImageForAPageThatMustBeRendered(): void
    {
        $this->write($this->vectorPdf());

        $document = PdfDocument::open($this->path);
        self::assertSame(1, $document->pageCount());
        self::assertNull($document->pageImage(1));
    }

    /**
     * A page built from several images is a composition, and picking one of
     * them would show the reader a fragment of the page.
     */
    public function testReportsNoImageForAPageComposedOfSeveralImages(): void
    {
        $this->write($this->twoImagePagePdf());

        self::assertNull(PdfDocument::open($this->path)->pageImage(1));
    }

    /** @dataProvider outOfRangeProvider */
    public function testRejectsPagesOutsideTheDocument(int $page): void
    {
        $this->write($this->imagePdf([$this->jpeg()]));

        $this->expectException(PdfException::class);
        PdfDocument::open($this->path)->pageImage($page);
    }

    public function outOfRangeProvider(): iterable
    {
        yield 'zero' => [0];
        yield 'past the end' => [2];
    }

    /**
     * Plenty of real PDFs have wrong cross-reference offsets and open fine in
     * every viewer, because viewers fall back to scanning for object headers.
     */
    public function testRecoversFromACrossReferenceTableWithWrongOffsets(): void
    {
        $jpeg = $this->jpeg();
        $pdf = $this->imagePdf([$jpeg, $jpeg]);
        // Corrupt every offset in the table without touching the objects.
        $broken = preg_replace('/^\d{10} 00000 n $/m', '9999999999 00000 n ', $pdf);
        self::assertNotSame($pdf, $broken);
        $this->write((string) $broken);

        $document = PdfDocument::open($this->path);
        self::assertSame(2, $document->pageCount());
        self::assertSame($jpeg, $document->pageImage(1)?->content);
    }

    /**
     * A trailer holding a nested dictionary is captured truncated by the scan
     * that recovers a broken file, so parsing it fails. That has to move on to
     * the next candidate rather than reject a document the catalogue scan would
     * have recovered.
     */
    public function testRecoversFromABrokenTableWhoseTrailerHasANestedDictionary(): void
    {
        $jpeg = $this->jpeg();
        $pdf = $this->imagePdf([$jpeg]);
        $pdf = str_replace('<< /Size', '<< /Info << /Producer (nested) >> /Size', $pdf);
        $pdf = (string) preg_replace('/^\d{10} 00000 n $/m', '9999999999 00000 n ', $pdf);
        $this->write($pdf);

        $document = PdfDocument::open($this->path);

        self::assertSame(1, $document->pageCount());
        self::assertSame($jpeg, $document->pageImage(1)?->content);
    }

    public function testRefusesAnEncryptedDocument(): void
    {
        $pdf = $this->imagePdf([$this->jpeg()]);
        $pdf = str_replace('/Root 1 0 R', '/Root 1 0 R /Encrypt 99 0 R', $pdf);
        $this->write($pdf);

        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('Encrypted');
        PdfDocument::open($this->path);
    }

    public function testRefusesSomethingThatIsNotAPdf(): void
    {
        $this->write("PK\x03\x04 not a pdf at all");

        $this->expectException(PdfException::class);
        PdfDocument::open($this->path);
    }

    public function testOpeningASmallDocumentDoesNotReserveTheEntireSizeLimit(): void
    {
        $php = (new PhpExecutableFinder())->find();
        if ($php === false) self::markTestSkipped('No PHP binary to run the isolated check with.');

        $this->write($this->imagePdf([$this->jpeg()]));

        $script = <<<'PHP'
            require getenv('APP_AUTOLOAD');
            echo App\ComicSource\Pdf\PdfDocument::open(getenv('APP_PDF'))->pageCount();
        PHP;

        $process = new Process([$php, '-d', 'memory_limit=64M', '-r', $script]);
        $process->setEnv([
            'APP_AUTOLOAD' => \dirname(__DIR__, 4).'/vendor/autoload.php',
            'APP_PDF' => $this->path,
        ]);
        $process->run();

        self::assertTrue($process->isSuccessful(), $process->getErrorOutput());
        self::assertSame('1', $process->getOutput());
    }

    public function testAcceptsADocumentPastThePreviousSizeLimit(): void
    {
        $pdf = $this->imagePdf([$this->jpeg()]);
        $this->write($pdf);

        $handle = fopen($this->path, 'c+b');
        self::assertIsResource($handle);
        self::assertSame(0, fseek($handle, 67_108_865 - strlen($pdf)));
        self::assertSame(strlen($pdf), fwrite($handle, $pdf));
        fclose($handle);

        self::assertSame(1, PdfDocument::open($this->path)->pageCount());
    }

    public function testRefusesAnOversizedDocumentBeforeReadingItIntoMemory(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'comic-pdfdoc-');
        $handle = fopen($this->path, 'c+b');
        self::assertIsResource($handle);
        self::assertSame(9, fwrite($handle, "%PDF-1.4\n"));
        self::assertTrue(ftruncate($handle, 524_288_001));
        fclose($handle);

        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('too large for the native reader');

        PdfDocument::open($this->path);
    }

    /**
     * A page tree pointing back at itself must terminate rather than recurse
     * until the process dies.
     */
    public function testRefusesAPageTreeThatPointsAtItself(): void
    {
        $pdf = "%PDF-1.4\n";
        $objects = [
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [2 0 R] /Count 1 >>',
        ];
        $offsets = [];
        foreach ($objects as $number => $body) {
            $offsets[$number] = strlen($pdf);
            $pdf .= "$number 0 obj\n$body\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 3\n0000000000 65535 f \n";
        foreach ([1, 2] as $number) $pdf .= sprintf("%010d 00000 n \n", $offsets[$number]);
        $pdf .= "trailer\n<< /Size 3 /Root 1 0 R >>\nstartxref\n$xref\n%%EOF\n";
        $this->write($pdf);

        $this->expectException(PdfException::class);
        PdfDocument::open($this->path)->pageCount();
    }

    /**
     * Plenty of exports store pages as bitmaps rather than JPEGs. Those are raw
     * pixel rows once inflated, which is what a PNG carries, so they are served
     * natively too — without which "PDF works without Poppler" would only be
     * half true.
     *
     * @dataProvider rawImageProvider
     */
    public function testServesPagesStoredAsRawBitmaps(
        string $colourSpace,
        int $bitsPerComponent,
        string $samples,
        int $width,
        int $height,
        string $extra,
    ): void {
        $this->write($this->rawImagePdf($colourSpace, $bitsPerComponent, $samples, $width, $height, $extra));

        $image = PdfDocument::open($this->path)->pageImage(1);

        self::assertNotNull($image, 'A raw bitmap page should be servable natively.');
        self::assertSame('image/png', $image->mimeType);

        $size = getimagesizefromstring($image->content);
        self::assertIsArray($size, 'The result should be a decodable image.');
        self::assertSame($width, $size[0]);
        self::assertSame($height, $size[1]);
    }

    public function rawImageProvider(): iterable
    {
        // 2x2 red/green/blue/white.
        yield 'DeviceRGB 8-bit' => [
            '/DeviceRGB', 8,
            "\xFF\x00\x00\x00\xFF\x00\x00\x00\xFF\xFF\xFF\xFF",
            2, 2, '',
        ];

        yield 'DeviceGray 8-bit' => ['/DeviceGray', 8, "\x00\x40\x80\xFF", 2, 2, ''];

        // An ICC profile still describes RGB underneath.
        yield 'ICCBased RGB' => [
            '[/ICCBased 90 0 R]', 8,
            "\xFF\x00\x00\x00\xFF\x00\x00\x00\xFF\xFF\xFF\xFF",
            2, 2, '',
        ];

        // Indexed: two palette entries, one byte per pixel.
        yield 'Indexed palette' => [
            '[/Indexed /DeviceRGB 1 <FF000000FF00>]', 8,
            "\x00\x01\x01\x00",
            2, 2, '',
        ];

        // Bilevel scans, the other common shape, including the inverted form.
        yield '1-bit bilevel' => ['/DeviceGray', 1, "\x80\x40", 2, 2, ''];
        yield '1-bit bilevel inverted' => ['/DeviceGray', 1, "\x80\x40", 2, 2, '/Decode [1 0]'];
    }

    /**
     * CMYK has no PNG equivalent, and converting it without the profile would
     * shift every colour on the page. Declining hands it to Poppler instead.
     *
     * @dataProvider unservableProvider
     */
    public function testDeclinesColourSpacesItCannotRepackFaithfully(string $colourSpace, int $bits, string $samples): void
    {
        $this->write($this->rawImagePdf($colourSpace, $bits, $samples, 2, 2, ''));

        self::assertNull(PdfDocument::open($this->path)->pageImage(1));
    }

    public function unservableProvider(): iterable
    {
        yield 'DeviceCMYK' => ['/DeviceCMYK', 8, str_repeat("\x10\x20\x30\x40", 4)];
        yield 'ICCBased CMYK' => ['[/ICCBased 91 0 R]', 8, str_repeat("\x10\x20\x30\x40", 4)];
    }

    public function testDeclinesAJpeg2000Page(): void
    {
        $this->write($this->imagePdf([$this->jpeg()], '/JPXDecode'));

        self::assertNull(PdfDocument::open($this->path)->pageImage(1));
    }

    /**
     * Exhausting memory or time is a fatal error: nothing after it runs, so the
     * renderer fallback is skipped and an upload is left stranded on disk. Each
     * of these is a small file that used to get there, and they are run for real
     * in their own process under the limit, because the claim is about the
     * limit. Whether a file is usable matters less than that it gets an answer.
     *
     * @dataProvider hostileDocumentProvider
     */
    public function testAHostileDocumentGetsAnAnswerRatherThanAFatal(callable $build, string $memoryLimit): void
    {
        $this->write($build($this));

        $result = $this->readInIsolation(['memory_limit' => $memoryLimit]);

        self::assertArrayHasKey('peak', $result);
    }

    public function hostileDocumentProvider(): iterable
    {
        yield 'one compressed cross-reference range grows beyond memory' => [
            static function (self $t): string {
                $pdf = "%PDF-1.5\n";
                $xref = strlen($pdf);
                $stream = $t->flateStream('/Type /XRef /Size 500000 /W [1 4 2] /Index [1 499999] /Root 1 0 R', str_repeat(pack('CNn', 2, 10, 0), 499_999));

                return $pdf."500000 0 obj\n$stream\nendobj\nstartxref\n$xref\n%%EOF\n";
            },
            '64M',
        ];

        yield 'one classic cross-reference range grows beyond memory' => [
            static function (): string {
                $pdf = "%PDF-1.4\n";
                $xref = strlen($pdf);
                $pdf .= "xref\n1000000 499999\n".str_repeat("0000000009 00000 n \n", 499_999);

                return $pdf."trailer\n<< /Size 1500000 /Root 1000000 0 R >>\nstartxref\n$xref\n%%EOF\n";
            },
            '64M',
        ];

        yield 'a predictor row wider than memory' => [
            static fn (self $t): string => $t->predictorPdf('/Predictor 12 /Columns 2000000000'),
            '64M',
        ];

        yield 'object headers that materialise into arrays' => [
            static fn (): string => "%PDF-1.4\n".str_repeat("1 0 obj\n", 500_000)."<< /Type /Catalog >>\n",
            '64M',
        ];

        yield 'trailers that materialise into arrays' => [
            static fn (): string => "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n".str_repeat('trailer<<>>', 500_000),
            '64M',
        ];

        yield 'a grey palette expanded from a compression bomb' => [
            static fn (self $t): string => $t->assemble([
                1 => '<< /Type /Catalog /Pages 2 0 R >>',
                2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
                3 => '<< /Type /Page /MediaBox [0 0 400 400] /Resources << /XObject << /Im0 4 0 R >> >> /Contents 5 0 R >>',
                4 => $t->flateStream('/Subtype /Image /Width 2 /Height 2 /BitsPerComponent 8 /ColorSpace [/Indexed /DeviceGray 255 6 0 R]', "\0\0\0\0"),
                5 => "<< /Length 30 >>\nstream\nq 400 0 0 400 0 0 cm /Im0 Do Q\nendstream",
                6 => $t->flateStream('', str_repeat("\0", 20_000_000)),
            ]),
            '64M',
        ];

        yield 'an object stream header of millions of pairs' => [
            static function (self $t): string {
                $header = str_repeat('1 1 ', 4_000_000);

                return $t->crossReferenceStreamPdf(
                    [10 => $t->flateStream('/Type /ObjStm /N 1 /First '.strlen($header), $header.'<< /Type /Catalog >>')],
                    [1 => [2, 10, 0]],
                );
            },
            '64M',
        ];

        yield 'one stream reached under many object numbers' => [
            static function (self $t): string {
                $names = '';
                for ($i = 0; $i < 200; ++$i) $names .= sprintf('/F%d %d 0 R ', $i, 10 + $i);

                $pdf = "%PDF-1.4\n";
                $offsets = [];
                $objects = [
                    1 => '<< /Type /Catalog /Pages 2 0 R >>',
                    2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
                    3 => "<< /Type /Page /MediaBox [0 0 400 400] /Resources << /XObject << $names >> >> >>",
                    9 => "<< /Subtype /Form /Length 1000000 >>\nstream\n".str_repeat('x', 1_000_000)."\nendstream",
                ];
                foreach ($objects as $number => $body) {
                    $offsets[$number] = strlen($pdf);
                    $pdf .= "$number 0 obj\n$body\nendobj\n";
                }

                $xref = strlen($pdf);
                $pdf .= "xref\n0 4\n0000000000 65535 f \n";
                foreach ([1, 2, 3] as $number) $pdf .= sprintf("%010d 00000 n \n", $offsets[$number]);
                $pdf .= "10 200\n".str_repeat(sprintf("%010d 00000 n \n", $offsets[9]), 200);

                return $pdf."trailer\n<< /Size 210 /Root 1 0 R >>\nstartxref\n$xref\n%%EOF\n";
            },
            '64M',
        ];

        yield 'containers whose filters live in further containers' => [
            static function (self $t): string {
                $levels = 60_000;
                $streams = [];
                $entries = [];
                for ($level = 0; $level < $levels; ++$level) {
                    $streams[1_000_000 + $level] = sprintf("<</N 1/First 4/Filter %d 0 R/Length 1>>\nstream\nx\nendstream", $level + 2);
                    $entries[$level + 1] = [2, 1_000_000 + $level, 0];
                }

                return $t->crossReferenceStreamPdf($streams, $entries);
            },
            '64M',
        ];

        yield 'cross-reference subsections that each stay under the limit' => [
            static function (): string {
                $pdf = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n";
                $xref = strlen($pdf);
                $pdf .= "xref\n";
                for ($section = 0; $section < 5; ++$section) {
                    $pdf .= ($section * 500_000)." 500000\n".str_repeat("0000000009 00000 n \n", 500_000);
                }

                return $pdf."trailer\n<< /Size 1 /Root 1 0 R >>\nstartxref\n$xref\n%%EOF\n";
            },
            '128M',
        ];
    }

    /**
     * The same fatal by the other limit: each object is cheap to hold and costs
     * a full inflation to reach, and alternating containers defeats any cache.
     */
    public function testADocumentThatWouldOutlastTheTimeLimitGetsAnAnswer(): void
    {
        $container = $this->flateStream('/Type /ObjStm /N 2 /First 8', '0 0 0 0 '.str_repeat(' ', 60_000_000).'null');
        $kids = '';
        $entries = [];
        for ($i = 0; $i < 2_000; ++$i) {
            $kids .= sprintf('%d 0 R ', 100 + $i);
            $entries[100 + $i] = [2, 30_000 + $i % 2, $i % 2];
        }

        $this->write($this->crossReferenceStreamPdf(
            [
                1 => '<< /Type /Catalog /Pages 2 0 R >>',
                2 => "<< /Type /Pages /Kids [$kids] /Count 2000 >>",
                30_000 => $container,
                30_001 => $container,
            ],
            $entries,
        ));

        $result = $this->readInIsolation(['memory_limit' => '256M', 'max_execution_time' => '4']);

        self::assertStringContainsString('more time', (string) ($result['error'] ?? ''));
    }

    /**
     * A source larger than the memory left must be declined before the read,
     * which is what lets the renderer have it.
     */
    public function testDeclinesASourceThatCannotFitInTheMemoryLeft(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'comic-pdfdoc-');
        $handle = fopen($this->path, 'c+b');
        self::assertIsResource($handle);
        fwrite($handle, "%PDF-1.4\n");
        self::assertTrue(ftruncate($handle, 100_000_000));
        fclose($handle);

        $result = $this->readInIsolation(['memory_limit' => '64M']);

        self::assertStringContainsString('too large for the native reader', (string) ($result['error'] ?? ''));
        self::assertLessThan(32, $result['peak'], 'The source should have been declined before it was read.');
    }

    public function testAnUnlimitedMemoryLimitDeclinesNothing(): void
    {
        $pdf = $this->imagePdf([$this->jpeg()]);
        $this->write($pdf);
        $handle = fopen($this->path, 'c+b');
        self::assertIsResource($handle);
        self::assertSame(0, fseek($handle, 100_000_000));
        self::assertSame(strlen($pdf), fwrite($handle, $pdf));
        fclose($handle);

        $result = $this->readInIsolation(['memory_limit' => '-1']);

        self::assertSame(1, $result['pages'] ?? null, (string) ($result['error'] ?? ''));
    }

    /** Bounding the predictor must not have cost the streams that really use it. */
    public function testStillUndoesAValidPredictor(): void
    {
        $rows = "\x00\x10\x20\x02\x01\x01";
        $this->write($this->predictorPdf('/Predictor 12 /Columns 2', $rows));

        $image = PdfDocument::open($this->path)->pageImage(1);

        self::assertNotNull($image);
        $decoded = imagecreatefromstring($image->content);
        self::assertNotFalse($decoded);
        // The second row was "up" filtered, so each byte adds the one above it.
        self::assertSame(0x11, imagecolorsforindex($decoded, imagecolorat($decoded, 0, 1))['red']);
        self::assertSame(0x21, imagecolorsforindex($decoded, imagecolorat($decoded, 1, 1))['red']);
    }

    /** @dataProvider impossiblePredictorProvider */
    public function testDeclinesAPredictorThatCouldNotExist(string $parms): void
    {
        $this->write($this->predictorPdf($parms));

        self::assertNull(PdfDocument::open($this->path)->pageImage(1));
    }

    public function impossiblePredictorProvider(): iterable
    {
        yield 'negative columns' => ['/Predictor 12 /Columns -1'];
        yield 'zero colours' => ['/Predictor 12 /Columns 2 /Colors 0'];
        yield 'too many colours' => ['/Predictor 12 /Columns 2 /Colors 1000'];
        yield 'a depth that does not exist' => ['/Predictor 12 /Columns 2 /BitsPerComponent 7'];
        yield 'columns that are not a number' => ['/Predictor 12 /Columns /Wide'];
        yield 'a product past the integer range' => ['/Predictor 12 /Columns 9223372036854775807 /Colors 16 /BitsPerComponent 16'];
    }

    /**
     * Serving the embedded image for any of these would quietly lose part of
     * what the page shows, so each has to be left to the renderer.
     *
     * @dataProvider notJustTheImageProvider
     */
    public function testLeavesAPageToTheRendererWhenItIsMoreThanItsImage(string $page, string $content, string $image = '', array $extra = []): void
    {
        $this->write($this->pagePdf($page, $content, $image, $extra));

        $document = PdfDocument::open($this->path);

        self::assertNull($document->pageImage(1));
        self::assertNotNull($document->soleImage(1), 'The image alone should still be available for a host with no renderer.');
    }

    public function notJustTheImageProvider(): iterable
    {
        $draw = 'q 400 0 0 400 0 0 cm /Im0 Do Q';

        yield 'text over the image' => ['', "$draw BT /F0 12 Tf 10 10 Td (Hello) Tj ET"];
        yield 'invisible text is still more than was checked' => ['', "$draw BT 3 Tr ET"];
        yield 'vector art over the image' => ['', "$draw 0 0 1 rg 10 10 100 100 re f"];
        yield 'a stroked line' => ['', "$draw 0 0 m 400 400 l S"];
        yield 'a shading' => ['', "$draw /Sh0 sh"];
        yield 'an inline image' => ['', "$draw BI /W 1 /H 1 /CS /G /BPC 8 ID x EI"];
        yield 'the image drawn twice' => ['', "$draw $draw"];
        yield 'a form drawn as well' => ['', "$draw /Fm0 Do", '', [6 => "<< /Subtype /Form /BBox [0 0 1 1] /Length 0 >>\nstream\n\nendstream"]];
        yield 'an image not filling the page' => ['', 'q 200 0 0 400 0 0 cm /Im0 Do Q'];
        yield 'an image offset from the page' => ['', 'q 400 0 0 400 50 0 cm /Im0 Do Q'];
        yield 'an image bleeding past the page' => ['', 'q 500 0 0 500 -50 -50 cm /Im0 Do Q'];
        yield 'an image flipped' => ['', 'q 400 0 0 -400 0 400 cm /Im0 Do Q'];
        yield 'an image turned' => ['', 'q 0 400 -400 0 400 0 cm /Im0 Do Q'];
        yield 'an image that is never drawn' => ['', '0 0 1 rg'];
        yield 'no content at all' => ['', ''];
        yield 'a clip smaller than the page' => ['', "0 0 100 100 re W n $draw"];
        yield 'unbalanced state' => ['', "$draw Q"];
        yield 'a page rotated' => ['/Rotate 90', $draw];
        yield 'a page cropped inside its image' => ['/CropBox [50 50 350 350]', $draw];
        yield 'annotations' => ['/Annots [<< /Subtype /Stamp >>]', $draw];
        yield 'a soft mask on the image' => ['', $draw, '/SMask 7 0 R', [7 => "<< /Subtype /Image /Width 1 /Height 1 /Length 1 >>\nstream\nx\nendstream"]];
        yield 'a colour key mask on the image' => ['', $draw, '/Mask [0 0 0 0 0 0]'];
        yield 'transparency in the graphics state' => ['/Resources << /XObject << /Im0 4 0 R >> /ExtGState << /G0 << /ca 0.5 >> >> >>', "/G0 gs $draw"];
        yield 'a blend mode in the graphics state' => ['/Resources << /XObject << /Im0 4 0 R >> /ExtGState << /G0 << /BM /Multiply >> >> >>', "/G0 gs $draw"];
        yield 'a graphics state that is not there' => ['', "/G0 gs $draw"];
        yield 'optional content around the image' => ['', "/OC /L0 BDC $draw EMC"];
    }

    /**
     * What real writers emit for a page that really is one image: the check
     * has to accept these, or every such page pays for a pointless render.
     *
     * @dataProvider justTheImageProvider
     */
    public function testServesThePageWhenItIsVerifiablyJustItsImage(string $page, string $content, array $extra = []): void
    {
        $this->write($this->pagePdf($page, $content, '', $extra));

        self::assertSame($this->jpeg(), PdfDocument::open($this->path)->pageImage(1)?->content);
    }

    public function justTheImageProvider(): iterable
    {
        $draw = 'q 400 0 0 400 0 0 cm /Im0 Do Q';

        yield 'img2pdf and ImageMagick' => ['', "q\n400 0 0 400 0 0 cm\n/Im0 Do\nQ\n"];
        yield 'Ghostscript scaling twice' => ['', "q 0.1 0 0 0.1 0 0 cm\nq\n4000 0 0 4000 0 0 cm\n/Im0 Do\nQ\nQ"];
        yield 'placement rounded by the writer' => ['', 'q 400.24 0 0 399.76 0 0 cm /Im0 Do Q'];
        yield 'a clip as large as the page' => ['', "q 0 0 400 400 re W n $draw Q"];
        yield 'empty state and colour first' => ['', "q Q /Cs1 cs 1 1 1 sc q $draw Q"];
        yield 'a comment' => ['', "% scanned\n$draw"];
        yield 'a neutral graphics state' => ['/Resources << /XObject << /Im0 4 0 R >> /ExtGState << /G0 << /Type /ExtGState /ca 1 /CA 1 /BM /Normal /SMask /None /OP false >> >> >>', "/G0 gs $draw"];
        yield 'a page with its origin elsewhere' => ['/MediaBox [100 100 500 500]', 'q 400 0 0 400 100 100 cm /Im0 Do Q'];
        yield 'a crop box the image fills exactly' => ['/CropBox [50 50 350 350]', 'q 300 0 0 300 50 50 cm /Im0 Do Q'];
        yield 'a crop box as large as the page' => ['/CropBox [0 0 400 400]', $draw];
        yield 'a whole turn is no turn' => ['/Rotate 360', $draw];
        yield 'no annotations' => ['/Annots []', $draw];
        yield 'content split between streams' => ['/Contents [5 0 R 6 0 R]', 'q 400 0 0 400 0 0 cm', [6 => "<< /Length 10 >>\nstream\n/Im0 Do Q \nendstream"]];
    }

    public function testChecksContentThatIsItselfCompressed(): void
    {
        $this->write($this->pagePdf('/Contents 6 0 R', '', '', [6 => $this->flateStream('', 'q 400 0 0 400 0 0 cm /Im0 Do Q')]));

        self::assertSame($this->jpeg(), PdfDocument::open($this->path)->pageImage(1)?->content);
    }

    public function testDoesNotInflateAContentBombToLookAtIt(): void
    {
        $this->write($this->pagePdf('/Contents 6 0 R', '', '', [6 => $this->flateStream('', str_repeat(' ', 100_000_000))]));

        $result = $this->readInIsolation(['memory_limit' => '64M']);

        self::assertSame('none', $result['image'] ?? null);
    }

    public function testTheBoundsOfAPageCanBeInherited(): void
    {
        $jpeg = $this->jpeg();
        $this->write($this->assemble([
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 /MediaBox [0 0 400 400] /Rotate 90 >>',
            3 => '<< /Type /Page /Parent 2 0 R /Resources << /XObject << /Im0 4 0 R >> >> /Contents 5 0 R >>',
            4 => $this->jpegImage($jpeg),
            5 => "<< /Length 30 >>\nstream\nq 400 0 0 400 0 0 cm /Im0 Do Q\nendstream",
        ]));

        self::assertNull(PdfDocument::open($this->path)->pageImage(1), 'An inherited rotation still rotates the page.');
    }

    /**
     * @param array<string, string> $ini
     * @return array<string, mixed>
     */
    private function readInIsolation(array $ini): array
    {
        $php = (new PhpExecutableFinder())->find();
        if ($php === false) self::markTestSkipped('No PHP binary to run the isolated check with.');

        $script = <<<'PHP'
            require getenv('APP_AUTOLOAD');
            $result = [];
            try {
                $document = App\ComicSource\Pdf\PdfDocument::open(getenv('APP_PDF'));
                $result['pages'] = $document->pageCount();
                $result['image'] = $document->pageImage(1)?->mimeType ?? 'none';
            } catch (App\ComicSource\Pdf\PdfException $exception) {
                $result['error'] = $exception->getMessage();
            }
            $result['peak'] = memory_get_peak_usage(true) >> 20;
            echo json_encode($result);
        PHP;

        $arguments = [$php];
        foreach ($ini + ['display_errors' => '1', 'log_errors' => '0'] as $key => $value) {
            array_push($arguments, '-d', "$key=$value");
        }

        $process = new Process([...$arguments, '-r', $script]);
        $process->setEnv([
            'APP_AUTOLOAD' => \dirname(__DIR__, 4).'/vendor/autoload.php',
            'APP_PDF' => $this->path,
        ]);
        $process->setTimeout(120);
        $process->run();

        self::assertTrue($process->isSuccessful(), 'The reader died: '.$process->getOutput().$process->getErrorOutput());
        $result = json_decode($process->getOutput(), true);
        self::assertIsArray($result, 'Unexpected output: '.$process->getOutput());

        return $result;
    }

    /** One page whose image is two rows of 2x1 gray samples with the given filter parameters. */
    private function predictorPdf(string $parms, string $rows = "\0\0\0\0"): string
    {
        return $this->assemble([
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            3 => '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 400 400] /Resources << /XObject << /Im0 4 0 R >> >> /Contents 5 0 R >>',
            4 => $this->flateStream("/Subtype /Image /Width 2 /Height 2 /ColorSpace /DeviceGray /BitsPerComponent 8 /DecodeParms << $parms >>", $rows),
            5 => "<< /Length 30 >>\nstream\nq 400 0 0 400 0 0 cm /Im0 Do Q\nendstream",
        ]);
    }

    private function flateStream(string $dictionary, string $data): string
    {
        $compressed = gzcompress($data, 9);

        return "<< $dictionary /Filter /FlateDecode /Length ".strlen($compressed).">>\nstream\n$compressed\nendstream";
    }

    /**
     * Objects in the file, plus entries for objects held in object streams,
     * indexed by a cross-reference stream as a PDF 1.5 writer would.
     *
     * @param array<int, string> $objects
     * @param array<int, array{int, int, int}> $entries object number => [type, a, b]
     */
    private function crossReferenceStreamPdf(array $objects, array $entries): string
    {
        $pdf = "%PDF-1.5\n";
        foreach ($objects as $number => $body) {
            $entries[$number] = [1, strlen($pdf), 0];
            $pdf .= "$number 0 obj\n$body\nendobj\n";
        }

        $self = max(array_keys($entries)) + 1;
        $entries[$self] = [1, strlen($pdf), 0];
        ksort($entries);

        $rows = '';
        $index = [];
        foreach ($entries as $number => $entry) {
            $rows .= pack('CNn', ...$entry);
            array_push($index, $number, 1);
        }

        $xref = strlen($pdf);
        $stream = $this->flateStream(sprintf('/Type /XRef /Size %d /W [1 4 2] /Index [%s] /Root 1 0 R', $self + 1, implode(' ', $index)), $rows);

        return $pdf."$self 0 obj\n$stream\nendobj\nstartxref\n$xref\n%%EOF\n";
    }

    /**
     * One image on one page, with whatever the case adds to either.
     *
     * @param array<int, string> $extra further objects
     */
    private function pagePdf(string $page, string $content, string $image = '', array $extra = []): string
    {
        $keys = str_contains($page, '/MediaBox') ? '' : '/MediaBox [0 0 400 400] ';
        $keys .= str_contains($page, '/Resources') ? '' : '/Resources << /XObject << /Im0 4 0 R /Fm0 6 0 R >> >> ';
        $keys .= str_contains($page, '/Contents') ? '' : '/Contents 5 0 R ';

        return $this->assemble($extra + [
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            3 => "<< /Type /Page /Parent 2 0 R $keys$page >>",
            4 => $this->jpegImage($this->jpeg(), $image),
            5 => sprintf("<< /Length %d >>\nstream\n%s\nendstream", strlen($content), $content),
        ]);
    }

    private function jpegImage(string $jpeg, string $extra = ''): string
    {
        $size = getimagesizefromstring($jpeg);

        return sprintf(
            "<< /Type /XObject /Subtype /Image /Width %d /Height %d /ColorSpace /DeviceRGB /BitsPerComponent 8 %s /Filter /DCTDecode /Length %d >>\nstream\n%s\nendstream",
            $size[0],
            $size[1],
            $extra,
            strlen($jpeg),
            $jpeg
        );
    }

    private function jpeg(): string
    {
        return (string) base64_decode(self::TINY_JPEG_BASE64, true);
    }

    /**
     * One page whose image is uncompressed samples in the given colour space.
     */
    private function rawImagePdf(string $colourSpace, int $bits, string $samples, int $width, int $height, string $extra): string
    {
        $compressed = gzcompress($samples, 6);

        return $this->assemble([
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            3 => '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 400 400] /Resources << /XObject << /Im0 4 0 R >> >> /Contents 5 0 R >>',
            4 => sprintf(
                "<< /Type /XObject /Subtype /Image /Width %d /Height %d /ColorSpace %s /BitsPerComponent %d %s /Filter /FlateDecode /Length %d >>\nstream\n%s\nendstream",
                $width,
                $height,
                $colourSpace,
                $bits,
                $extra,
                strlen($compressed),
                $compressed
            ),
            5 => "<< /Length 30 >>\nstream\nq 400 0 0 400 0 0 cm /Im0 Do Q\nendstream",
            // Referenced by the ICCBased cases: /N is what says gray or RGB.
            90 => "<< /N 3 /Length 0 >>\nstream\n\nendstream",
            91 => "<< /N 4 /Length 0 >>\nstream\n\nendstream",
        ]);
    }

    /**
     * The shape a scanned or exported comic actually has: one page per
     * full-page DCTDecode image.
     *
     * @param list<string> $jpegs
     */
    private function imagePdf(array $jpegs, string $filter = "/DCTDecode"): string
    {
        $count = count($jpegs);
        $objects = [1 => '<< /Type /Catalog /Pages 2 0 R >>'];

        $next = 3;
        $pageNumbers = [];
        $imageNumbers = [];
        foreach ($jpegs as $index => $unused) $pageNumbers[$index] = $next++;
        foreach ($jpegs as $index => $unused) $imageNumbers[$index] = $next++;
        $contents = $next++;

        $kids = [];
        foreach ($jpegs as $index => $jpeg) {
            $kids[] = $pageNumbers[$index].' 0 R';
            $objects[$pageNumbers[$index]] = sprintf(
                '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 400 400] /Resources << /XObject << /Im0 %d 0 R >> >> /Contents %d 0 R >>',
                $imageNumbers[$index],
                $contents
            );
            $size = getimagesizefromstring($jpeg);
            $objects[$imageNumbers[$index]] = sprintf(
                "<< /Type /XObject /Subtype /Image /Width %d /Height %d /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter %s /Length %d >>\nstream\n%s\nendstream",
                $size[0],
                $size[1],
                $filter,
                strlen($jpeg),
                $jpeg
            );
        }

        $objects[2] = sprintf('<< /Type /Pages /Kids [%s] /Count %d >>', implode(' ', $kids), $count);
        $objects[$contents] = "<< /Length 30 >>\nstream\nq 400 0 0 400 0 0 cm /Im0 Do Q\nendstream";

        return $this->assemble($objects);
    }

    private function vectorPdf(): string
    {
        return $this->assemble([
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            3 => '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 400 400] /Resources << >> /Contents 4 0 R >>',
            4 => "<< /Length 27 >>\nstream\n0 0 1 rg 10 10 100 100 re f\nendstream",
        ]);
    }

    private function twoImagePagePdf(): string
    {
        $jpeg = $this->jpeg();
        $size = getimagesizefromstring($jpeg);
        $image = sprintf(
            "<< /Type /XObject /Subtype /Image /Width %d /Height %d /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length %d >>\nstream\n%s\nendstream",
            $size[0],
            $size[1],
            strlen($jpeg),
            $jpeg
        );

        return $this->assemble([
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            3 => '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 400 400] /Resources << /XObject << /Im0 4 0 R /Im1 5 0 R >> >> /Contents 6 0 R >>',
            4 => $image,
            5 => $image,
            6 => "<< /Length 30 >>\nstream\nq 400 0 0 400 0 0 cm /Im0 Do Q\nendstream",
        ]);
    }

    /** @param array<int, string> $objects */
    private function assemble(array $objects): string
    {
        ksort($objects);
        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $number => $body) {
            $offsets[$number] = strlen($pdf);
            $pdf .= "$number 0 obj\n$body\nendobj\n";
        }

        $highest = (int) max(array_keys($objects));
        $xref = strlen($pdf);
        $pdf .= sprintf("xref\n0 %d\n0000000000 65535 f \n", $highest + 1);
        for ($number = 1; $number <= $highest; ++$number) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$number] ?? 0);
        }

        return $pdf.sprintf("trailer\n<< /Size %d /Root 1 0 R >>\nstartxref\n%d\n%%%%EOF\n", $highest + 1, $xref);
    }

    private function write(string $contents): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'comic-pdfdoc-');
        file_put_contents($this->path, $contents);
    }

    protected function tearDown(): void
    {
        if ($this->path !== null && is_file($this->path)) unlink($this->path);
        $this->path = null;
        parent::tearDown();
    }
}
