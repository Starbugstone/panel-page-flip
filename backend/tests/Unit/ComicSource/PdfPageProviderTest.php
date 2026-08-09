<?php

namespace App\Tests\Unit\ComicSource;

use App\ComicSource\PdfPageProvider;
use App\Enum\ComicSourceType;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

final class PdfPageProviderTest extends TestCase
{
    private ?string $pdfPath = null;

    public function testInspectsAndRendersOnePage(): void
    {
        $finder = new ExecutableFinder();
        if ($finder->find('pdfinfo') === null || $finder->find('pdftocairo') === null) {
            self::markTestSkipped('Poppler is not installed.');
        }

        $this->pdfPath = tempnam(sys_get_temp_dir(), 'comic-pdf-test-');
        file_put_contents($this->pdfPath, $this->onePagePdf());
        $provider = new PdfPageProvider(new LockFactory(new FlockStore(sys_get_temp_dir())));

        self::assertSame(1, $provider->inspect($this->pdfPath, ComicSourceType::PDF)->pageCount);
        $page = $provider->readPage($this->pdfPath, ComicSourceType::PDF, 1);
        self::assertSame('image/jpeg', $page->mimeType);
        self::assertNotSame('', $page->content);
    }

    /**
     * A reader asks for the page you are on and prefetches the next one, so
     * renders overlap during ordinary reading. Holding all but one slot still
     * has to leave a render possible; the earlier single global lock refused
     * the second concurrent request outright.
     */
    public function testRendersWhileOtherRendersHoldSlots(): void
    {
        $finder = new ExecutableFinder();
        if ($finder->find('pdfinfo') === null || $finder->find('pdftocairo') === null) {
            self::markTestSkipped('Poppler is not installed.');
        }

        $this->pdfPath = tempnam(sys_get_temp_dir(), 'comic-pdf-test-');
        file_put_contents($this->pdfPath, $this->onePagePdf());
        $lockFactory = new LockFactory(new FlockStore(sys_get_temp_dir()));

        $held = [];
        foreach (['comic-pdf-render-0', 'comic-pdf-render-1'] as $slot) {
            $lock = $lockFactory->createLock($slot, 35.0);
            self::assertTrue($lock->acquire());
            $held[] = $lock;
        }

        try {
            $page = (new PdfPageProvider($lockFactory))->readPage($this->pdfPath, ComicSourceType::PDF, 1);
            self::assertSame('image/jpeg', $page->mimeType);
        } finally {
            foreach ($held as $lock) $lock->release();
        }
    }

    /**
     * qpdf is the optional structural opinion, so this only asserts a rejection
     * where qpdf is actually installed to give one.
     */
    public function testRejectsAStructurallyDamagedDocument(): void
    {
        $finder = new ExecutableFinder();
        if ($finder->find('pdfinfo') === null || $finder->find('qpdf') === null) {
            self::markTestSkipped('Poppler and qpdf are not both installed.');
        }

        $this->pdfPath = tempnam(sys_get_temp_dir(), 'comic-pdf-test-');
        // A valid header over a body qpdf cannot resolve into objects.
        file_put_contents($this->pdfPath, "%PDF-1.4\n".str_repeat("\x01\x02\x03\x04", 64));
        $provider = new PdfPageProvider(new LockFactory(new FlockStore(sys_get_temp_dir())));

        $this->expectException(\RuntimeException::class);
        $provider->inspect($this->pdfPath, ComicSourceType::PDF);
    }

    /**
     * Logical page N must render page N, exactly as a CBZ's Nth image entry is
     * its Nth page. Each page gets a different shape, so the rendered image
     * itself proves which page Poppler was pointed at — a page-count assertion
     * alone would pass even if every request rendered page one.
     */
    public function testEachLogicalPageRendersThatPageAndNotTheFirst(): void
    {
        $finder = new ExecutableFinder();
        if ($finder->find('pdfinfo') === null || $finder->find('pdftocairo') === null) {
            self::markTestSkipped('Poppler is not installed.');
        }

        $this->pdfPath = tempnam(sys_get_temp_dir(), 'comic-pdf-test-');
        // Portrait, landscape, square.
        file_put_contents($this->pdfPath, $this->pdf([[200, 400], [400, 200], [300, 300]]));
        $provider = new PdfPageProvider(new LockFactory(new FlockStore(sys_get_temp_dir())));

        self::assertSame(3, $provider->inspect($this->pdfPath, ComicSourceType::PDF)->pageCount);

        $shape = function (int $page) use ($provider): string {
            $size = getimagesizefromstring($provider->readPage($this->pdfPath, ComicSourceType::PDF, $page)->content);
            self::assertIsArray($size);
            return $size[0] < $size[1] ? 'portrait' : ($size[0] > $size[1] ? 'landscape' : 'square');
        };

        self::assertSame('portrait', $shape(1));
        self::assertSame('landscape', $shape(2));
        self::assertSame('square', $shape(3));
    }

    /** @dataProvider outOfRangeProvider */
    public function testRejectsPagesOutsideTheDocument(int $page): void
    {
        $finder = new ExecutableFinder();
        if ($finder->find('pdfinfo') === null) self::markTestSkipped('Poppler is not installed.');

        $this->pdfPath = tempnam(sys_get_temp_dir(), 'comic-pdf-test-');
        file_put_contents($this->pdfPath, $this->pdf([[200, 200], [200, 200]]));
        $provider = new PdfPageProvider(new LockFactory(new FlockStore(sys_get_temp_dir())));

        $this->expectException(\OutOfRangeException::class);
        $provider->readPage($this->pdfPath, ComicSourceType::PDF, $page);
    }

    public function outOfRangeProvider(): iterable
    {
        yield 'zero' => [0];
        yield 'negative' => [-1];
        yield 'past the end' => [3];
    }

    public function testRejectsAFileThatIsNotAPdf(): void
    {
        $this->pdfPath = tempnam(sys_get_temp_dir(), 'comic-pdf-test-');
        file_put_contents($this->pdfPath, "PK\x03\x04 this is a zip, not a pdf");
        $provider = new PdfPageProvider(new LockFactory(new FlockStore(sys_get_temp_dir())));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid PDF signature.');
        $provider->inspect($this->pdfPath, ComicSourceType::PDF);
    }

    /**
     * Encrypted documents are refused rather than half-supported, and the
     * message has to stay stable because the upload path surfaces it.
     *
     * The two cases reach the rejection by different routes. A user password
     * stops Poppler reading the document at all, so the refusal comes off
     * stderr; an owner-password-only document opens perfectly and has to be
     * caught by reading the encryption flag. Both are real files in the wild.
     *
     * @dataProvider encryptionProvider
     */
    public function testRejectsAnEncryptedDocument(string $userPassword): void
    {
        $finder = new ExecutableFinder();
        $qpdf = $finder->find('qpdf');
        if ($finder->find('pdfinfo') === null || $qpdf === null) {
            self::markTestSkipped('Poppler and qpdf are not both installed.');
        }

        $plain = tempnam(sys_get_temp_dir(), 'comic-pdf-plain-');
        file_put_contents($plain, $this->pdf([[200, 200]]));
        $this->pdfPath = tempnam(sys_get_temp_dir(), 'comic-pdf-test-');

        // 256-bit because qpdf refuses to write the weaker RC4 variants.
        $encrypt = new Process([$qpdf, '--encrypt', $userPassword, 'ownerpw', '256', '--', $plain, $this->pdfPath]);
        $encrypt->run();
        unlink($plain);
        if ($encrypt->getExitCode() !== 0) self::markTestSkipped('qpdf could not produce an encrypted fixture.');

        $provider = new PdfPageProvider(new LockFactory(new FlockStore(sys_get_temp_dir())));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Encrypted PDFs are not supported.');
        $provider->inspect($this->pdfPath, ComicSourceType::PDF);
    }

    public function encryptionProvider(): iterable
    {
        yield 'user password blocks Poppler entirely' => ['readerpw'];
        yield 'owner password only, document still opens' => [''];
    }

    private function onePagePdf(): string
    {
        return $this->pdf([[200, 200]]);
    }

    /**
     * A minimal but structurally valid PDF with one page per given [width,
     * height], sharing a single empty content stream.
     *
     * @param list<array{0: int, 1: int}> $pageSizes
     */
    private function pdf(array $pageSizes): string
    {
        $pageCount = count($pageSizes);
        // 1 catalog, 2 page tree, 3..N+2 pages, N+3 shared content stream.
        $contentObject = $pageCount + 3;
        $kids = implode(' ', array_map(static fn (int $i): string => sprintf('%d 0 R', $i + 3), range(0, $pageCount - 1)));

        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            sprintf('<< /Type /Pages /Kids [%s] /Count %d >>', $kids, $pageCount),
        ];
        foreach ($pageSizes as [$width, $height]) {
            $objects[] = sprintf(
                '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %d %d] /Resources << >> /Contents %d 0 R >>',
                $width,
                $height,
                $contentObject
            );
        }
        $objects[] = "<< /Length 0 >>\nstream\n\nendstream";

        $pdf = "%PDF-1.4\n";
        $offsets = [0];
        foreach ($objects as $index => $object) {
            $offsets[] = strlen($pdf);
            $pdf .= sprintf("%d 0 obj\n%s\nendobj\n", $index + 1, $object);
        }

        $total = count($objects);
        $xref = strlen($pdf);
        $pdf .= sprintf("xref\n0 %d\n0000000000 65535 f \n", $total + 1);
        for ($index = 1; $index <= $total; ++$index) $pdf .= sprintf("%010d 00000 n \n", $offsets[$index]);

        return $pdf.sprintf("trailer\n<< /Size %d /Root 1 0 R >>\nstartxref\n%d\n%%%%EOF\n", $total + 1, $xref);
    }

    protected function tearDown(): void
    {
        if ($this->pdfPath !== null && is_file($this->pdfPath)) unlink($this->pdfPath);
        $this->pdfPath = null;
        parent::tearDown();
    }
}
