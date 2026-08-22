<?php

namespace App\Tests\Unit\ComicSource;

use App\ComicSource\PdfPageProvider;
use App\Enum\ComicSourceType;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;
use App\Tests\Unit\ComicSource\Pdf\PdfDocumentTest;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\PhpExecutableFinder;
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

    /**
     * The point of native reading: an image-based comic PDF is served from its
     * own embedded JPEGs, so the reader gets the author's bytes rather than a
     * re-encode, and nothing is rendered.
     */
    public function testServesAnImagePdfFromItsEmbeddedPagesWithoutRendering(): void
    {
        $jpeg = $this->tinyJpeg();
        $this->pdfPath = tempnam(sys_get_temp_dir(), 'comic-pdf-test-');
        file_put_contents($this->pdfPath, $this->imagePdf([$jpeg, $jpeg]));

        $provider = new PdfPageProvider(new LockFactory(new FlockStore(sys_get_temp_dir())));

        self::assertSame(2, $provider->inspect($this->pdfPath, ComicSourceType::PDF)->pageCount);
        $page = $provider->readPage($this->pdfPath, ComicSourceType::PDF, 2);
        self::assertSame('image/jpeg', $page->mimeType);
        self::assertSame($jpeg, $page->content, 'The embedded JPEG should be served untouched.');
    }

    /**
     * The whole reason for native reading: shared hosting forbids subprocesses,
     * and PDF has to remain as usable as CBZ there. Run for real with proc_open
     * gone, because that is the condition being claimed.
     */
    public function testReadsAnImagePdfOnAHostThatForbidsSubprocesses(): void
    {
        $php = (new PhpExecutableFinder())->find();
        if ($php === false) self::markTestSkipped('No PHP binary to run the isolated check with.');

        $jpeg = $this->tinyJpeg();
        $this->pdfPath = tempnam(sys_get_temp_dir(), 'comic-pdf-test-');
        file_put_contents($this->pdfPath, $this->imagePdf([$jpeg, $jpeg, $jpeg]));

        $script = <<<'PHP'
            require getenv('APP_AUTOLOAD');
            $provider = new App\ComicSource\PdfPageProvider(
                new Symfony\Component\Lock\LockFactory(
                    new Symfony\Component\Lock\Store\FlockStore(sys_get_temp_dir())
                )
            );
            $path = getenv('APP_PDF');
            $type = App\Enum\ComicSourceType::PDF;
            echo json_encode([
                'canShellOut' => App\ComicSource\ComicRuntimeProbe::canRunExternalTools(),
                'pages' => $provider->inspect($path, $type)->pageCount,
                'sha' => hash('sha256', $provider->readPage($path, $type, 3)->content),
                'mime' => $provider->readPage($path, $type, 3)->mimeType,
            ]);
        PHP;

        $process = new Process([$php, '-d', 'disable_functions=proc_open', '-r', $script]);
        $process->setEnv([
            'APP_AUTOLOAD' => \dirname(__DIR__, 3).'/vendor/autoload.php',
            'APP_PDF' => $this->pdfPath,
        ]);
        $process->run();

        if (!$process->isSuccessful()) {
            self::fail('Reading a PDF without subprocesses failed: '.$process->getErrorOutput());
        }

        $result = json_decode($process->getOutput(), true);
        self::assertIsArray($result, 'Unexpected output: '.$process->getOutput());
        self::assertFalse($result['canShellOut'], 'The isolated run was supposed to have proc_open disabled.');
        self::assertSame(3, $result['pages']);
        self::assertSame('image/jpeg', $result['mime']);
        self::assertSame(hash('sha256', $jpeg), $result['sha'], 'The embedded JPEG should be served untouched.');
    }

    private function onePagePdf(): string
    {
        return $this->pdf([[200, 200]]);
    }

    private function tinyJpeg(): string
    {
        return (string) base64_decode(PdfDocumentTest::TINY_JPEG_BASE64, true);
    }

    /**
     * One full-page DCTDecode image per page, which is what a scanned or
     * exported comic PDF is.
     *
     * @param list<string> $jpegs
     */
    private function imagePdf(array $jpegs): string
    {
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

        $objects[2] = sprintf('<< /Type /Pages /Kids [%s] /Count %d >>', implode(' ', $kids), count($jpegs));
        $objects[$contents] = "<< /Length 30 >>\nstream\nq 400 0 0 400 0 0 cm /Im0 Do Q\nendstream";

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
        for ($number = 1; $number <= $highest; ++$number) $pdf .= sprintf("%010d 00000 n \n", $offsets[$number] ?? 0);

        return $pdf.sprintf("trailer\n<< /Size %d /Root 1 0 R >>\nstartxref\n%d\n%%%%EOF\n", $highest + 1, $xref);
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

    /* ---------------------------------------------------------------------- */
    /* The structural check is a second opinion, not a gate                    */
    /* ---------------------------------------------------------------------- */

    /**
     * The regression this exists for.
     *
     * `qpdf --check` reads every object in the document, so a large scan takes
     * longer than a flat budget allowed. The check is explicitly optional — an
     * installation without qpdf imports on the Poppler checks alone — but a
     * timeout escaped as an exception and became "not a valid or supported
     * comic source". The slowest files are the large legitimate ones, so the
     * effect was that ordinary 100 MB manga volumes could not be uploaded at
     * all, failing at the very end of a long upload.
     */
    public function testAStructuralCheckThatRunsOutOfTimeDoesNotRejectTheDocument(): void
    {
        $finder = new ExecutableFinder();
        if ($finder->find('pdfinfo') === null) {
            self::markTestSkipped('Poppler is not installed.');
        }
        if ($finder->find('qpdf') === null) {
            self::markTestSkipped('qpdf is not installed, so there is no check to time out.');
        }

        $this->pdfPath = tempnam(sys_get_temp_dir(), 'comic-pdf-test-');
        file_put_contents($this->pdfPath, $this->onePagePdf());

        // A budget the document passes the size gate on, paired with a clock
        // nothing can finish inside — which is what a slow host does to a file
        // small enough to have been worth checking.
        $provider = new PdfPageProvider(
            new LockFactory(new FlockStore(sys_get_temp_dir())),
            null,
            null,
            8.0,
            0.000001,
        );

        self::assertSame(
            1,
            $provider->inspect($this->pdfPath, ComicSourceType::PDF)->pageCount,
            'A structural check that timed out must not reject a readable PDF.',
        );
    }

    /** A document qpdf can actually make sense of is still checked properly. */
    public function testAReadableDocumentStillPassesTheStructuralCheck(): void
    {
        $finder = new ExecutableFinder();
        if ($finder->find('pdfinfo') === null) {
            self::markTestSkipped('Poppler is not installed.');
        }

        $this->pdfPath = tempnam(sys_get_temp_dir(), 'comic-pdf-test-');
        file_put_contents($this->pdfPath, $this->onePagePdf());
        $provider = new PdfPageProvider(new LockFactory(new FlockStore(sys_get_temp_dir())));

        self::assertSame(1, $provider->inspect($this->pdfPath, ComicSourceType::PDF)->pageCount);
    }

    /**
     * The gate, which is the actual fix.
     *
     * `qpdf --check` costs about 0.4s per megabyte because it reads every
     * object in the document. Comic PDFs are routinely 100-500 MB, so the check
     * cannot finish on them inside any budget a web request can afford — and a
     * check that is started and abandoned has cost the same as one that
     * finished while answering nothing. So a document it cannot finish is never
     * started, rather than being timed out partway through.
     */
    public function testLargeDocumentsAreNotPutThroughACheckThatCannotFinish(): void
    {
        $budget = 8.0;

        // The single issues the check is genuinely worth running on.
        self::assertTrue(PdfPageProvider::isWorthChecking(5 * 1048576, $budget));
        self::assertTrue(PdfPageProvider::isWorthChecking(15 * 1048576, $budget));

        // The manga volumes that could not be uploaded at all.
        self::assertFalse(PdfPageProvider::isWorthChecking(121 * 1048576, $budget));
        self::assertFalse(PdfPageProvider::isWorthChecking(500 * 1048576, $budget));
    }

    /** The estimate is what the gate is built on, so it is asserted directly. */
    public function testTheCostEstimateTracksFileSize(): void
    {
        // The measurement the default is derived from: 57.7 MB took 20.9s.
        self::assertEqualsWithDelta(
            20.9,
            PdfPageProvider::estimatedCheckSeconds((int) (57.7 * 1048576)),
            3.0,
            'The default rate must stay close to the measured one.',
        );

        self::assertSame(0.0, PdfPageProvider::estimatedCheckSeconds(0));
        self::assertSame(0.0, PdfPageProvider::estimatedCheckSeconds(-1));
        self::assertGreaterThan(
            PdfPageProvider::estimatedCheckSeconds(10 * 1048576),
            PdfPageProvider::estimatedCheckSeconds(20 * 1048576),
        );
    }

    /**
     * The rate is a property of the host, not of this application: a slower
     * disk changes what the same budget can afford. The default was measured on
     * a development container, so an operator has to be able to correct it.
     */
    public function testASlowerHostChecksSmallerDocumentsForTheSameBudget(): void
    {
        $budget = 8.0;
        $twentyMegabytes = 20 * 1048576;

        // At the measured rate, 20 MB is exactly affordable.
        self::assertTrue(PdfPageProvider::isWorthChecking($twentyMegabytes, $budget, 0.4));

        // On a host three times slower, the same budget must buy less.
        self::assertFalse(PdfPageProvider::isWorthChecking($twentyMegabytes, $budget, 1.2));

        // And on a faster one, more.
        self::assertTrue(PdfPageProvider::isWorthChecking(60 * 1048576, $budget, 0.1));
    }

    /** A rate of zero is a misconfiguration, not a licence to check anything. */
    public function testAZeroOrNegativeRateFallsBackToTheMeasuredOne(): void
    {
        $huge = 500 * 1048576;

        self::assertFalse(PdfPageProvider::isWorthChecking($huge, 8.0, 0.0));
        self::assertFalse(PdfPageProvider::isWorthChecking($huge, 8.0, -1.0));
    }

    /** What the operator-facing command prints, which is the ratio that matters. */
    public function testTheReportedThresholdIsTheBudgetOverTheRate(): void
    {
        self::assertSame(20.0, PdfPageProvider::largestCheckedMegabytes(8.0, 0.4));
        self::assertSame(200.0, PdfPageProvider::largestCheckedMegabytes(30.0, 0.15));

        // Either setting at zero means nothing is checked.
        self::assertSame(0.0, PdfPageProvider::largestCheckedMegabytes(0.0, 0.4));
        self::assertSame(0.0, PdfPageProvider::largestCheckedMegabytes(8.0, 0.0));
    }

    /**
     * A budget of zero turns the check off, which is a supported configuration
     * rather than a degraded one — it is what a host without qpdf already does.
     */
    public function testAZeroBudgetDisablesTheCheckEntirely(): void
    {
        self::assertFalse(PdfPageProvider::isWorthChecking(1024, 0.0));
        self::assertFalse(PdfPageProvider::isWorthChecking(0, 0.0));
        self::assertFalse(PdfPageProvider::isWorthChecking(1024, -1.0));
    }

    /** And a disabled check still imports a perfectly good PDF. */
    public function testAPdfStillImportsWithTheCheckDisabled(): void
    {
        if ((new ExecutableFinder())->find('pdfinfo') === null) {
            self::markTestSkipped('Poppler is not installed.');
        }

        $this->pdfPath = tempnam(sys_get_temp_dir(), 'comic-pdf-test-');
        file_put_contents($this->pdfPath, $this->onePagePdf());
        $provider = new PdfPageProvider(
            new LockFactory(new FlockStore(sys_get_temp_dir())),
            null,
            null,
            0.0,
        );

        self::assertSame(1, $provider->inspect($this->pdfPath, ComicSourceType::PDF)->pageCount);
    }
}
