<?php

namespace App\Tests\Unit\ComicSource;

use App\ComicSource\PdfPageProvider;
use App\Enum\ComicSourceType;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;
use Symfony\Component\Process\ExecutableFinder;

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

    private function onePagePdf(): string
    {
        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 200 200] /Resources << >> /Contents 4 0 R >>',
            "<< /Length 0 >>\nstream\n\nendstream",
        ];
        $pdf = "%PDF-1.4\n";
        $offsets = [0];
        foreach ($objects as $index => $object) {
            $offsets[] = strlen($pdf);
            $pdf .= sprintf("%d 0 obj\n%s\nendobj\n", $index + 1, $object);
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 5\n0000000000 65535 f \n";
        for ($index = 1; $index <= 4; ++$index) $pdf .= sprintf("%010d 00000 n \n", $offsets[$index]);
        return $pdf."trailer\n<< /Size 5 /Root 1 0 R >>\nstartxref\n".$xref."\n%%EOF\n";
    }

    protected function tearDown(): void
    {
        if ($this->pdfPath !== null && is_file($this->pdfPath)) unlink($this->pdfPath);
        $this->pdfPath = null;
        parent::tearDown();
    }
}
