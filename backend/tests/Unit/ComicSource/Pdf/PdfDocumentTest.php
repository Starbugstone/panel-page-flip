<?php

namespace App\Tests\Unit\ComicSource\Pdf;

use App\ComicSource\Pdf\PdfDocument;
use App\ComicSource\Pdf\PdfException;
use PHPUnit\Framework\TestCase;

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

    public function testRefusesAnOversizedDocumentBeforeReadingItIntoMemory(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'comic-pdfdoc-');
        $handle = fopen($this->path, 'c+b');
        self::assertIsResource($handle);
        self::assertSame(9, fwrite($handle, "%PDF-1.4\n"));
        self::assertTrue(ftruncate($handle, 67_108_865));
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
