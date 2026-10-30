<?php

namespace App\ComicSource;

use App\Enum\ComicSourceType;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

final class SevenZipPageProvider implements ComicPageProviderInterface, ComicInfoSourceInterface
{
    private const TIMEOUT = 20.0;
    /** @var array<string, list<string>> */ private array $indexes = [];

    public function __construct(private readonly ?CacheInterface $pageIndexCache = null)
    {
    }

    public function supports(ComicSourceType $type): bool { return in_array($type, [ComicSourceType::CBR, ComicSourceType::CB7, ComicSourceType::CBT], true); }
    public function inspect(string $sourcePath, ComicSourceType $type): ComicSourceInfo { return new ComicSourceInfo(count($this->index($sourcePath, $type))); }
    /** The target width is ignored, for the reason given in ZipPageProvider. */
    public function readPage(string $sourcePath, ComicSourceType $type, int $page, ?int $targetWidth = null): PageResult
    {
        $pages = $this->index($sourcePath, $type);
        if ($page < 1 || !isset($pages[$page - 1])) throw new \OutOfRangeException('Page not found.');
        $content = $this->runWithBoundedOutput(
            ['7z', 'x', '-so', '-spd', '--', $sourcePath, $pages[$page - 1]],
            ComicSourceLimits::MAX_PAGE_BYTES,
            'Archive page exceeds the safety limit.',
        );
        if ($content === '') throw new \RuntimeException('Archive page extraction failed.');
        return PageResult::fromImageContent($content);
    }
    /** @return list<string> */
    private function index(string $path, ComicSourceType $type): array
    {
        $key = $path.'|'.(@filemtime($path) ?: 0).'|'.(@filesize($path) ?: 0);
        if (isset($this->indexes[$key])) return $this->indexes[$key];
        if ($this->pageIndexCache === null) return $this->indexes[$key] = $this->buildIndex($path, $type);

        $cacheKey = 'comic_source.7z.'.hash('xxh128', $key.'|'.$type->value);
        return $this->indexes[$key] = $this->pageIndexCache->get($cacheKey, function (ItemInterface $item) use ($path, $type): array {
            $item->expiresAfter(86_400);
            return $this->buildIndex($path, $type);
        });
    }

    public function readComicInfoXml(string $sourcePath, ComicSourceType $type): ?string
    {
        try {
            $entry = $this->comicInfoEntry($this->listing($sourcePath, $type));
            if ($entry === null) return null;

            $xml = $this->runWithBoundedOutput(
                ['7z', 'x', '-so', '-spd', '--', $sourcePath, $entry],
                ComicSourceLimits::MAX_METADATA_BYTES,
                'ComicInfo.xml exceeds the safety limit.',
            );
        } catch (\RuntimeException) {
            return null;
        }

        if ($xml === '') return null;

        return $xml;
    }

    private function comicInfoEntry(string $listing): ?string
    {
        foreach (preg_split('/\R/', $listing) ?: [] as $line) {
            if (!str_starts_with($line, 'Path = ')) continue;
            $path = substr($line, 7);
            if (ZipPageProvider::isComicInfoEntry($path)) return $path;
        }

        return null;
    }

    /** Raw `7z l -slt` output, once the archive is confirmed to match its extension. */
    private function listing(string $path, ComicSourceType $type): string
    {
        $failureMessage = $type === ComicSourceType::CBR
            ? 'Could not read this RAR archive. Check Admin → Formats: CBR needs a 7z built with RAR support.'
            : sprintf('Could not read this %s archive with 7z.', strtoupper($type->value));
        $listing = $this->runWithBoundedOutput(
            ['7z', 'l', '-slt', '--', $path],
            ComicSourceLimits::MAX_LISTING_BYTES,
            'Archive listing exceeds the safety limit.',
            $failureMessage,
        );
        if (!preg_match('/^Type = (\S+)/mi', $listing, $format)) throw new \RuntimeException('Archive format could not be identified.');
        $actual = strtolower($format[1]);
        $expected = match ($type) { ComicSourceType::CBR => ['rar', 'rar5'], ComicSourceType::CB7 => ['7z'], ComicSourceType::CBT => ['tar'], default => [] };
        if (!in_array($actual, $expected, true)) throw new \RuntimeException('Archive content does not match its extension.');

        return $listing;
    }

    /** @param non-empty-list<string> $command */
    private function runWithBoundedOutput(array $command, int $limitBytes, string $limitMessage, string $failureMessage = 'Archive page extraction failed.'): string
    {
        if (!\function_exists('proc_open')) throw new \RuntimeException($failureMessage);

        // Symfony's pipe reader may accumulate a continuously written pipe
        // before invoking its callback. Read fixed chunks ourselves so the
        // safety check runs before any unbounded buffer can be allocated.
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) throw new \RuntimeException($failureMessage);

        $output = '';
        $receivedBytes = 0;
        $deadline = microtime(true) + self::TIMEOUT;
        fclose($pipes[0]);
        unset($pipes[0]);
        foreach ($pipes as $pipe) stream_set_blocking($pipe, false);

        try {
            while ($pipes !== []) {
                if (microtime(true) > $deadline) throw new \RuntimeException('Archive command timed out.');
                $ready = $pipes;
                $write = $except = [];
                if (@stream_select($ready, $write, $except, 0, 200_000) === false) throw new \RuntimeException($failureMessage);

                foreach ($ready as $channel => $pipe) {
                    $chunk = fread($pipe, 8192);
                    if ($chunk === false) throw new \RuntimeException($failureMessage);
                    $chunkBytes = strlen($chunk);
                    if (ComicSourceLimits::wouldExceedByteLimit($receivedBytes, $chunkBytes, $limitBytes)
                        || !MemoryBudget::fits(2 * ($receivedBytes + $chunkBytes))) {
                        throw new \RuntimeException($limitMessage);
                    }
                    $receivedBytes += $chunkBytes;
                    if ($channel === 1) $output .= $chunk;
                    if (feof($pipe)) {
                        fclose($pipe);
                        unset($pipes[$channel]);
                    }
                }
            }

            $exitCode = proc_close($process);
            $process = null;
            if ($exitCode !== 0) throw new \RuntimeException($failureMessage);

            return $output;
        } finally {
            foreach ($pipes as $pipe) fclose($pipe);
            if (is_resource($process)) {
                proc_terminate($process, 9);
                proc_close($process);
            }
        }
    }

    /** @return list<string> */
    private function buildIndex(string $path, ComicSourceType $type): array
    {
        $listing = $this->listing($path, $type);
        $pages = []; $entries = 0; $total = 0; $currentPath = null; $inEntries = false;
        foreach (preg_split('/\R/', $listing) ?: [] as $line) {
            if ($line === '----------') { $inEntries = true; continue; }
            if (!$inEntries) continue;
            if (str_starts_with($line, 'Path = ')) { ++$entries; $currentPath = substr($line, 7); if (ZipPageProvider::isSafeImage($currentPath)) $pages[] = $currentPath; }
            if (str_starts_with($line, 'Size = ')) {
                $entrySize = max(0, (int) substr($line, 7));
                $total += $entrySize;
                if ($currentPath !== null && ZipPageProvider::isSafeImage($currentPath) && $entrySize > ComicSourceLimits::MAX_PAGE_BYTES) throw new \RuntimeException('Archive contains an oversized page.');
            }
            if ($entries > ComicSourceLimits::MAX_ENTRIES || $total > ComicSourceLimits::MAX_UNCOMPRESSED_BYTES) throw new \RuntimeException('Archive exceeds safety limits.');
        }
        if (ComicSourceLimits::hasUnsafeExpansionRatio($total, (int) @filesize($path))) {
            throw new \RuntimeException('Archive has an unsafe compression ratio.');
        }
        usort($pages, 'strnatcasecmp'); if ($pages === []) throw new \RuntimeException('Archive contains no supported pages.');
        return $pages;
    }
}
