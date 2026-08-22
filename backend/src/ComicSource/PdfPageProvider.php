<?php

namespace App\ComicSource;

use App\ComicSource\Pdf\PdfDocument;
use App\ComicSource\Pdf\PdfException;
use App\Enum\ComicSourceType;
use Psr\Log\LoggerInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\LockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

final class PdfPageProvider implements ComicPageProviderInterface
{
    /**
     * Rendering is the expensive part of serving a PDF page, so only a few may
     * run at once. A request waits for a free slot rather than failing on the
     * first busy moment: the reader asks for the page you are on and prefetches
     * the next one at the same time, so a single reader is routinely two
     * renders deep and refusing one of those shows a broken page to somebody
     * who did nothing wrong.
     */
    private const MAX_CONCURRENT_RENDERS = 3;
    private const SLOT_WAIT_SECONDS = 20.0;
    private const SLOT_POLL_MICROSECONDS = 100_000;
    private const SLOT_TTL_SECONDS = 35.0;
    private const RENDER_TIMEOUT_SECONDS = 30;
    private const INSPECT_TIMEOUT_SECONDS = 15;

    /**
     * What `qpdf --check` costs, measured rather than guessed.
     *
     * It reads and validates every object in the document, so its cost is
     * linear in *file size* — about 0.4 seconds per megabyte on this project's
     * reference container (11.5 MB in 5.5s; 57.7 MB in 20.9s). Every other step
     * in the acceptance path is flat: `pdfinfo` answers in ~0.05s and rendering
     * page one in ~0.15s, whatever the document weighs.
     *
     * That asymmetry is the whole problem. A comic PDF is routinely 100-500 MB,
     * which at this rate is 40 seconds to three minutes of a PHP-FPM worker —
     * spent on a *second opinion* about a document that the very next step
     * proves servable by rendering a page of it with the renderer this check
     * exists to second-guess.
     *
     * So the check is gated on whether it can finish inside a fixed budget,
     * rather than being started on everything and abandoned partway. Being
     * abandoned costs the same as finishing and buys nothing.
     *
     * The rate is configurable because it is a property of the *host*, not of
     * this application: a slower disk or a busier CPU changes it, and the
     * default here was measured on a development container rather than on
     * anybody's server. `app:comic-formats:check` reports what the rate implies
     * so an operator can compare it against their own hardware.
     */
    private const DEFAULT_STRUCTURE_CHECK_SECONDS_PER_MEGABYTE = 0.4;

    /**
     * Eight seconds, which at the rate above admits documents up to ~20 MB.
     *
     * Small enough that no upload waits noticeably on a second opinion, and
     * large enough to cover the single-issue PDFs where a silent structural
     * fault is most likely to go unnoticed.
     */
    private const DEFAULT_STRUCTURE_CHECK_BUDGET = 8.0;

    /** Head-room over the budget, for a host slower than the one measured. */
    private const STRUCTURE_CHECK_TIMEOUT_FACTOR = 3.0;

    /**
     * Bounds on what a caller's size hint may ask the renderer to draw. The
     * ceiling is what a full-size reader page is worth; the floor keeps a
     * thumbnail request from producing something too small to be a page.
     */
    private const MAX_RENDER_PIXELS = 2400;
    private const MIN_RENDER_PIXELS = 320;

    /** @var array<string, ComicSourceInfo> */
    private array $inspections = [];

    /** @var array<string, ComicSourceInfo> */
    private array $pageCounts = [];

    /**
     * Only the document last asked for, and the key it belongs to.
     *
     * A parsed document holds the whole file in memory. Keeping one per source
     * is right for an HTTP request, which reads one comic, and wrong for the
     * import command and the Dropbox sync, where a single provider instance
     * walks a whole library in one process and would accumulate every PDF it
     * ever opened. One entry still gives a reader turning pages the
     * parse-once benefit, since those requests are all for the same file.
     */
    private ?string $documentKey = null;
    private ?PdfDocument $document = null;

    public function __construct(
        private readonly LockFactory $lockFactory,
        private readonly ?CacheInterface $pageIndexCache = null,
        private readonly ?LoggerInterface $logger = null,
        /**
         * How long the optional structural check may cost per document.
         *
         * Zero turns it off, which is a supported configuration rather than a
         * degraded one: the check has always been optional, and an installation
         * without qpdf imports PDFs on the Poppler checks alone.
         */
        #[Autowire('%pdf_structure_check_budget_seconds%')]
        private readonly float $structureCheckBudget = self::DEFAULT_STRUCTURE_CHECK_BUDGET,
        /**
         * What the check costs per megabyte on *this* host.
         *
         * Measured on a development container, so it is the number most worth
         * correcting on real hardware: it is what converts the budget above
         * into "documents up to N megabytes are checked".
         */
        #[Autowire('%pdf_structure_check_seconds_per_megabyte%')]
        private readonly float $structureCheckRate = self::DEFAULT_STRUCTURE_CHECK_SECONDS_PER_MEGABYTE,
        // A seam for the tests, which need a check that reliably runs out of
        // time without a pathological document to make it do so honestly.
        private readonly ?float $structureCheckTimeout = null,
    ) {
    }

    public function supports(ComicSourceType $type): bool { return $type === ComicSourceType::PDF; }

    /**
     * Full acceptance check, run once when a source is imported.
     *
     * This is where a document has to prove it is actually servable, because
     * accepting one now and discovering at page three that nothing on this
     * host can read it is the failure users notice. Page one is read here for
     * that reason as well as for the cover.
     */
    public function inspect(string $sourcePath, ComicSourceType $type): ComicSourceInfo
    {
        $key = $this->sourceKey($sourcePath);
        if (isset($this->inspections[$key])) return $this->inspections[$key];

        // Order matters for the message the uploader sees. Signature first, so
        // something that was never a PDF costs no subprocess. Then the page
        // count, which is what distinguishes "encrypted" from "broken" — qpdf
        // cannot decrypt either, so running it first labelled every
        // password-protected comic as damaged. The structural check goes last,
        // on documents already known to be readable and unencrypted.
        $this->assertPdfSignature($sourcePath);
        $info = $this->pageCount($sourcePath);
        $this->assertStructurallySound($sourcePath);

        return $this->inspections[$key] = $info;
    }

    /**
     * Native first, Poppler second.
     *
     * A comic PDF is nearly always a container holding one full-page image per
     * page, which is what a CBZ is with a different wrapper. Where that holds,
     * the page is served by handing over the embedded image: no subprocess, no
     * rasterising, no quality lost re-encoding the author's own JPEG, and it
     * works on hosting that forbids running external programs at all. Poppler
     * is then what handles the pages that genuinely need drawing.
     */
    public function readPage(string $sourcePath, ComicSourceType $type, int $page, ?int $targetWidth = null): PageResult
    {
        $info = $this->pageCount($sourcePath);
        if ($page < 1 || $page > $info->pageCount) throw new \OutOfRangeException('Page not found.');

        $embedded = $this->embeddedPage($sourcePath, $page);
        if ($embedded !== null) return $embedded;

        if (!ComicRuntimeProbe::canRunExternalTools()) {
            throw new \RuntimeException('This page is not a single embedded image, and this server cannot run a PDF renderer. Poppler is needed to read this document.');
        }

        return $this->render($sourcePath, $page, $targetWidth);
    }

    /**
     * The page's own image, when the page is built that way. Never fatal: a
     * document this cannot read is one Poppler is asked about instead.
     */
    private function embeddedPage(string $sourcePath, int $page): ?PageResult
    {
        try {
            $image = $this->document($sourcePath)?->pageImage($page);
            if ($image === null) return null;

            // PageResult rejects an image past the page size limit, and an
            // oversized embedded scan is exactly the page a render would
            // succeed at by scaling it down. Anything this cannot hand over is
            // a question for the renderer, not an error.
            return new PageResult($image->content, $image->mimeType);
        } catch (\OutOfRangeException) {
            throw new \OutOfRangeException('Page not found.');
        } catch (\Throwable $exception) {
            $this->logger?->debug('Falling back to rendering for a PDF page.', ['reason' => $exception->getMessage()]);
            return null;
        }
    }

    /**
     * Draw one page, at roughly the size the caller means to serve it at.
     *
     * Rendering a 6000-pixel raster to hand a phone a 800-pixel image is the
     * expensive way to get the same picture: the cost is paid in the renderer,
     * not in the resize afterwards. The hint is clamped rather than trusted —
     * it decides how much work this server does.
     */
    private function render(string $sourcePath, int $page, ?int $targetWidth = null): PageResult
    {
        $renderLock = $this->acquireRenderSlot();
        $directory = sys_get_temp_dir().'/comic-pdf-'.bin2hex(random_bytes(12));
        try {
            if (!mkdir($directory, 0700)) throw new \RuntimeException('Cannot create render directory.');
            $prefix = $directory.'/page';
            $process = new Process([...['pdftocairo', '-f', (string) $page, '-l', (string) $page, '-singlefile'], ...$this->scaleArguments($targetWidth), ...['-jpeg', '-jpegopt', 'quality=88', $sourcePath, $prefix]]);
            $process->setTimeout(self::RENDER_TIMEOUT_SECONDS); $process->run(); $output = $prefix.'.jpg';
            if (!$process->isSuccessful() || !is_file($output)) throw new \RuntimeException('PDF page rendering failed.');
            $content = file_get_contents($output); if ($content === false) throw new \RuntimeException('Rendered page could not be read.');
            // Not source-sized: these dimensions describe this render, not the
            // page, so nothing may record them as the page's geometry.
            return PageResult::fromImageContent($content, false);
        } finally {
            foreach (glob($directory.'/*') ?: [] as $file) @unlink($file);
            @rmdir($directory);
            $renderLock->release();
        }
    }

    /**
     * Without a hint the long side is fitted to the maximum, which is what a
     * page read for its own sake — a cover, a geometry probe, an `original`
     * request — should get. With one, the width is set and the height follows
     * the page's own proportions.
     *
     * @return list<string>
     */
    private function scaleArguments(?int $targetWidth): array
    {
        if ($targetWidth === null) return ['-scale-to', (string) self::MAX_RENDER_PIXELS];

        $width = max(self::MIN_RENDER_PIXELS, min(self::MAX_RENDER_PIXELS, $targetWidth));

        return ['-scale-to-x', (string) $width, '-scale-to-y', '-1'];
    }

    /**
     * Take one of the render slots, waiting a bounded time for one to free up.
     *
     * The slots are separate lock keys rather than one shared lock because the
     * lock component has no counting primitive. The starting offset is random
     * so concurrent waiters do not all queue behind slot 0.
     */
    private function acquireRenderSlot(): LockInterface
    {
        $deadline = microtime(true) + self::SLOT_WAIT_SECONDS;
        $offset = random_int(0, self::MAX_CONCURRENT_RENDERS - 1);

        do {
            for ($attempt = 0; $attempt < self::MAX_CONCURRENT_RENDERS; ++$attempt) {
                $slot = ($offset + $attempt) % self::MAX_CONCURRENT_RENDERS;
                $lock = $this->lockFactory->createLock('comic-pdf-render-'.$slot, self::SLOT_TTL_SECONDS);
                if ($lock->acquire()) return $lock;
            }
            usleep(self::SLOT_POLL_MICROSECONDS);
        } while (microtime(true) < $deadline);

        throw new \RuntimeException('The PDF renderer is busy. Please retry shortly.');
    }

    /**
     * Page count and the rejections that have to happen before we render:
     * a real PDF header, a document Poppler can read, and no encryption.
     */
    private function pageCount(string $sourcePath): ComicSourceInfo
    {
        $key = $this->sourceKey($sourcePath);
        if (isset($this->pageCounts[$key])) return $this->pageCounts[$key];
        if ($this->pageIndexCache === null) return $this->pageCounts[$key] = $this->probePageCount($sourcePath);

        // The same treatment a CBZ's page index gets. Without it every page a
        // reader asks for pays for pdfinfo re-parsing the whole document to
        // re-learn a number that cannot change while the file is unchanged —
        // the key is path, mtime and size, so a replaced file re-probes.
        $cacheKey = 'comic_source.pdf.'.hash('xxh128', $key);
        $pages = $this->pageIndexCache->get($cacheKey, function (ItemInterface $item) use ($sourcePath): int {
            $item->expiresAfter(86_400);
            return $this->probePageCount($sourcePath)->pageCount;
        });

        return $this->pageCounts[$key] = new ComicSourceInfo($pages);
    }

    private function probePageCount(string $sourcePath): ComicSourceInfo
    {
        $this->assertPdfSignature($sourcePath);

        // Poppler is asked first where it exists, because it is the better
        // authority on a damaged or encrypted document and produces the
        // messages the uploader should see. Where it does not exist, the native
        // reader answers instead, which is what makes PDF work at all on
        // hosting that cannot run external programs.
        if (ComicRuntimeProbe::canRunExternalTools() && (new ExecutableFinder())->find('pdfinfo') !== null) {
            $process = new Process(['pdfinfo', $sourcePath]); $process->setTimeout(self::INSPECT_TIMEOUT_SECONDS); $process->run();
            if (!$process->isSuccessful()) throw new \RuntimeException(str_contains(strtolower($process->getErrorOutput()), 'password') ? 'Encrypted PDFs are not supported.' : 'PDF inspection failed.');
            if (preg_match('/^Encrypted:\s+yes/im', $process->getOutput())) throw new \RuntimeException('Encrypted PDFs are not supported.');
            if (!preg_match('/^Pages:\s+(\d+)/mi', $process->getOutput(), $match)) throw new \RuntimeException('PDF page count is unavailable.');

            return new ComicSourceInfo((int) $match[1]);
        }

        $document = $this->document($sourcePath);
        if ($document === null) throw new \RuntimeException('PDF inspection failed.');

        return new ComicSourceInfo($document->pageCount());
    }

    /**
     * The parsed document, kept for the length of the request so a reader
     * turning pages re-reads the cross-reference table once rather than once
     * per page.
     */
    private function document(string $sourcePath): ?PdfDocument
    {
        $key = $this->sourceKey($sourcePath);
        if ($this->documentKey === $key) return $this->document;

        // Release the previous document before parsing the next, so walking a
        // library never holds two files at once.
        $this->documentKey = $key;
        $this->document = null;

        try {
            return $this->document = PdfDocument::open($sourcePath);
        } catch (PdfException $exception) {
            // An encrypted document has to keep its own message, since the
            // uploader is told this one.
            if (str_contains($exception->getMessage(), 'Encrypted')) throw new \RuntimeException('Encrypted PDFs are not supported.');
            $this->logger?->debug('A PDF could not be read natively.', ['reason' => $exception->getMessage()]);
            return null;
        }
    }

    /**
     * qpdf's structural check, which catches damaged cross-reference tables and
     * object streams that Poppler will silently render as blank pages.
     *
     * Optional by design: it is a second opinion, not the mandatory gate, so an
     * installation without qpdf still imports PDFs on the Poppler checks alone.
     * Exit code 3 is "warnings only", which ordinary real-world files produce
     * in quantity; only 2 means qpdf could not make sense of the document.
     */
    private function assertStructurallySound(string $sourcePath): void
    {
        // ExecutableFinder happily reports a binary this host may not run, so
        // the subprocess check has to come first or Process throws.
        if (!ComicRuntimeProbe::canRunExternalTools()) return;

        $qpdf = (new ExecutableFinder())->find('qpdf');
        if ($qpdf === null) return;

        $bytes = (int) (@filesize($sourcePath) ?: 0);
        if (!self::isWorthChecking($bytes, $this->structureCheckBudget, $this->structureCheckRate)) {
            // Deliberately not attempted, rather than started and abandoned.
            // A document this size cannot be checked inside the budget, and a
            // check that runs out of time has cost the same as one that
            // finished while answering nothing.
            $this->logger?->info('PDF is too large for the structural check; importing on the Poppler checks alone.', [
                'bytes' => $bytes,
                'budget_seconds' => $this->structureCheckBudget,
            ]);

            return;
        }

        // The path is always an absolute, application-generated one from the
        // source resolver, so it can never be read as a qpdf option.
        $timeout = $this->structureCheckTimeout
            ?? max(1.0, $this->structureCheckBudget * self::STRUCTURE_CHECK_TIMEOUT_FACTOR);
        $process = new Process([$qpdf, '--check', '--no-warn', $sourcePath]);
        $process->setTimeout($timeout);

        try {
            $process->run();
        } catch (ProcessTimedOutException) {
            // A second opinion that did not arrive is not a negative one.
            //
            // This check is optional by design: an installation with no qpdf
            // imports PDFs on the Poppler checks alone. A check that runs out
            // of time has to land in that same place rather than condemning a
            // document it never finished reading — otherwise the slowest files,
            // which are the large legitimate ones, are the ones refused.
            $this->logger?->info('PDF structural check did not finish; importing on the Poppler checks alone.', [
                'timeout_seconds' => $timeout,
            ]);

            return;
        }

        if ($process->getExitCode() === 2) throw new \RuntimeException('PDF structure is damaged.');
    }

    /**
     * Whether the structural check can finish on this document inside `$budget`.
     *
     * Public and static so the gate can be asserted directly; nothing about it
     * needs a document, a subprocess or a temporary file to be tested.
     */
    public static function isWorthChecking(
        int $fileSizeBytes,
        float $budgetSeconds,
        float $secondsPerMegabyte = self::DEFAULT_STRUCTURE_CHECK_SECONDS_PER_MEGABYTE,
    ): bool {
        if ($budgetSeconds <= 0.0) return false;

        // A rate of zero would claim every document is free to check, which is
        // a misconfiguration rather than a licence to check a 500 MB file.
        if ($secondsPerMegabyte <= 0.0) {
            $secondsPerMegabyte = self::DEFAULT_STRUCTURE_CHECK_SECONDS_PER_MEGABYTE;
        }

        return self::estimatedCheckSeconds($fileSizeBytes, $secondsPerMegabyte) <= $budgetSeconds;
    }

    /** What the check is expected to cost on a document of this size. */
    public static function estimatedCheckSeconds(
        int $fileSizeBytes,
        float $secondsPerMegabyte = self::DEFAULT_STRUCTURE_CHECK_SECONDS_PER_MEGABYTE,
    ): float {
        return max(0, $fileSizeBytes) / 1048576 * $secondsPerMegabyte;
    }

    /** The largest document this budget and rate admit, for operator-facing output. */
    public static function largestCheckedMegabytes(float $budgetSeconds, float $secondsPerMegabyte): float
    {
        if ($budgetSeconds <= 0.0 || $secondsPerMegabyte <= 0.0) return 0.0;

        return $budgetSeconds / $secondsPerMegabyte;
    }

    private function assertPdfSignature(string $sourcePath): void
    {
        if (@file_get_contents($sourcePath, false, null, 0, 5) !== '%PDF-') throw new \RuntimeException('Invalid PDF signature.');
    }

    private function sourceKey(string $sourcePath): string
    {
        return $sourcePath.'|'.(@filemtime($sourcePath) ?: 0).'|'.(@filesize($sourcePath) ?: 0);
    }
}
