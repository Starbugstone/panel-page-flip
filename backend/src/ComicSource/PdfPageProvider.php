<?php

namespace App\ComicSource;

use App\ComicSource\Pdf\PdfDocument;
use App\ComicSource\Pdf\PdfException;
use App\Enum\ComicSourceType;
use Psr\Log\LoggerInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\LockInterface;
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

    /** @var array<string, ComicSourceInfo> */
    private array $inspections = [];

    /** @var array<string, ComicSourceInfo> */
    private array $pageCounts = [];

    /** @var array<string, PdfDocument|null> */
    private array $documents = [];

    public function __construct(
        private readonly LockFactory $lockFactory,
        private readonly ?CacheInterface $pageIndexCache = null,
        private readonly ?LoggerInterface $logger = null,
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
    public function readPage(string $sourcePath, ComicSourceType $type, int $page): PageResult
    {
        $info = $this->pageCount($sourcePath);
        if ($page < 1 || $page > $info->pageCount) throw new \OutOfRangeException('Page not found.');

        $embedded = $this->embeddedPage($sourcePath, $page);
        if ($embedded !== null) return $embedded;

        if (!ComicRuntimeProbe::canRunExternalTools()) {
            throw new \RuntimeException('This page is not a single embedded image, and this server cannot run a PDF renderer. Poppler is needed to read this document.');
        }

        return $this->render($sourcePath, $page);
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

            return new PageResult($image->content, $image->mimeType);
        } catch (PdfException $exception) {
            $this->logger?->debug('Falling back to rendering for a PDF page.', ['reason' => $exception->getMessage()]);
            return null;
        }
    }

    private function render(string $sourcePath, int $page): PageResult
    {
        $renderLock = $this->acquireRenderSlot();
        $directory = sys_get_temp_dir().'/comic-pdf-'.bin2hex(random_bytes(12));
        try {
            if (!mkdir($directory, 0700)) throw new \RuntimeException('Cannot create render directory.');
            $prefix = $directory.'/page';
            $process = new Process(['pdftocairo', '-f', (string) $page, '-l', (string) $page, '-singlefile', '-scale-to', '2400', '-jpeg', '-jpegopt', 'quality=88', $sourcePath, $prefix]);
            $process->setTimeout(self::RENDER_TIMEOUT_SECONDS); $process->run(); $output = $prefix.'.jpg';
            if (!$process->isSuccessful() || !is_file($output)) throw new \RuntimeException('PDF page rendering failed.');
            $content = file_get_contents($output); if ($content === false) throw new \RuntimeException('Rendered page could not be read.');
            return PageResult::fromImageContent($content);
        } finally {
            foreach (glob($directory.'/*') ?: [] as $file) @unlink($file);
            @rmdir($directory);
            $renderLock->release();
        }
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
        if (array_key_exists($key, $this->documents)) return $this->documents[$key];

        try {
            return $this->documents[$key] = PdfDocument::open($sourcePath);
        } catch (PdfException $exception) {
            // An encrypted document has to keep its own message, since the
            // uploader is told this one.
            if (str_contains($exception->getMessage(), 'Encrypted')) throw new \RuntimeException('Encrypted PDFs are not supported.');
            $this->logger?->debug('A PDF could not be read natively.', ['reason' => $exception->getMessage()]);
            return $this->documents[$key] = null;
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

        // The path is always an absolute, application-generated one from the
        // source resolver, so it can never be read as a qpdf option.
        $process = new Process([$qpdf, '--check', '--no-warn', $sourcePath]);
        $process->setTimeout(self::INSPECT_TIMEOUT_SECONDS);
        $process->run();

        if ($process->getExitCode() === 2) throw new \RuntimeException('PDF structure is damaged.');
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
