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

    private function jpeg(): string
    {
        return (string) base64_decode(self::TINY_JPEG_BASE64, true);
    }

    /**
     * The shape a scanned or exported comic actually has: one page per
     * full-page DCTDecode image.
     *
     * @param list<string> $jpegs
     */
    private function imagePdf(array $jpegs): string
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
                "<< /Type /XObject /Subtype /Image /Width %d /Height %d /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length %d >>\nstream\n%s\nendstream",
                $size[0],
                $size[1],
                strlen($jpeg),
                $jpeg
            );
        }

        $objects[2] = sprintf('<< /Type /Pages /Kids [%s] /Count %d >>', implode(' ', $kids), $count);
        $objects[$contents] = "<< /Length 29 >>\nstream\nq 400 0 0 400 0 0 cm /Im0 Do Q\nendstream";

        return $this->assemble($objects);
    }

    private function vectorPdf(): string
    {
        return $this->assemble([
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            3 => '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 400 400] /Resources << >> /Contents 4 0 R >>',
            4 => "<< /Length 45 >>\nstream\n0 0 1 rg 10 10 100 100 re f\nendstream",
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
            6 => "<< /Length 29 >>\nstream\nq 400 0 0 400 0 0 cm /Im0 Do Q\nendstream",
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
