<?php

namespace App\ComicSource;

use App\Enum\ComicSourceType;
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

    public function __construct(
        private readonly LockFactory $lockFactory,
        private readonly ?CacheInterface $pageIndexCache = null,
    ) {
    }

    public function supports(ComicSourceType $type): bool { return $type === ComicSourceType::PDF; }

    /**
     * Full acceptance check, run once when a source is imported.
     *
     * This is the expensive path — it adds qpdf's structural check on top of
     * the Poppler inspection — and it is deliberately not what `readPage()`
     * uses. Re-checking the structure of an already accepted document on every
     * page turn would spend a subprocess per page to re-learn something the
     * upload already established.
     */
    public function inspect(string $sourcePath, ComicSourceType $type): ComicSourceInfo
    {
        $key = $this->sourceKey($sourcePath);
        if (isset($this->inspections[$key])) return $this->inspections[$key];

        // Order matters for the message the uploader sees. Signature first, so
        // something that was never a PDF costs no subprocess. Then Poppler,
        // which is what distinguishes "encrypted" from "broken" — qpdf cannot
        // decrypt either, so running it first labelled every password-protected
        // comic as damaged. The structural check goes last, on documents that
        // are already known to be readable and unencrypted.
        $this->assertPdfSignature($sourcePath);
        $info = $this->pageCount($sourcePath);
        $this->assertStructurallySound($sourcePath);

        return $this->inspections[$key] = $info;
    }

    public function readPage(string $sourcePath, ComicSourceType $type, int $page): PageResult
    {
        $info = $this->pageCount($sourcePath);
        if ($page < 1 || $page > $info->pageCount) throw new \OutOfRangeException('Page not found.');

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
        $process = new Process(['pdfinfo', $sourcePath]); $process->setTimeout(self::INSPECT_TIMEOUT_SECONDS); $process->run();
        if (!$process->isSuccessful()) throw new \RuntimeException(str_contains(strtolower($process->getErrorOutput()), 'password') ? 'Encrypted PDFs are not supported.' : 'PDF inspection failed.');
        if (preg_match('/^Encrypted:\s+yes/im', $process->getOutput())) throw new \RuntimeException('Encrypted PDFs are not supported.');
        if (!preg_match('/^Pages:\s+(\d+)/mi', $process->getOutput(), $match)) throw new \RuntimeException('PDF page count is unavailable.');

        return new ComicSourceInfo((int) $match[1]);
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
