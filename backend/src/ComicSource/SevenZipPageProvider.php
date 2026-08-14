<?php

namespace App\ComicSource;

use App\Enum\ComicSourceType;
use Symfony\Component\Process\Process;
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
    public function readPage(string $sourcePath, ComicSourceType $type, int $page): PageResult
    {
        $pages = $this->index($sourcePath, $type);
        if ($page < 1 || !isset($pages[$page - 1])) throw new \OutOfRangeException('Page not found.');
        $process = new Process(['7z', 'x', '-so', '-spd', '--', $sourcePath, $pages[$page - 1]]);
        $process->setTimeout(self::TIMEOUT); $process->setInput(null); $process->run();
        if (!$process->isSuccessful() || $process->getOutput() === '') throw new \RuntimeException('Archive page extraction failed.');
        return PageResult::fromImageContent($process->getOutput());
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
        } catch (\RuntimeException) {
            return null;
        }

        if ($entry === null) return null;

        $process = new Process(['7z', 'x', '-so', '-spd', '--', $sourcePath, $entry]);
        $process->setTimeout(self::TIMEOUT); $process->setInput(null);

        // Read incrementally and stop at the cap. getOutput() would hold the
        // whole entry first, and how large that is was decided by whoever built
        // the archive.
        $xml = ''; $tooLarge = false;
        $process->run(static function (string $type, string $chunk) use (&$xml, &$tooLarge, $process): void {
            if ($type !== Process::OUT || $tooLarge) return;
            $xml .= $chunk;
            if (strlen($xml) > ComicSourceLimits::MAX_METADATA_BYTES) { $tooLarge = true; $process->stop(0); }
        });

        if ($tooLarge || !$process->isSuccessful() || $xml === '') return null;

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
        $process = new Process(['7z', 'l', '-slt', '--', $path]); $process->setTimeout(self::TIMEOUT); $process->run();
        if (!$process->isSuccessful()) {
            // "Is 7z installed?" sends an administrator looking in the wrong
            // place for CBR, where the usual cause is a 7z built without the
            // RAR handler rather than a missing binary. Admin → Formats reports
            // that distinction; this message points there.
            throw new \RuntimeException($type === ComicSourceType::CBR
                ? 'Could not read this RAR archive. Check Admin → Formats: CBR needs a 7z built with RAR support.'
                : sprintf('Could not read this %s archive with 7z.', strtoupper($type->value)));
        }
        if (!preg_match('/^Type = (\S+)/mi', $process->getOutput(), $format)) throw new \RuntimeException('Archive format could not be identified.');
        $actual = strtolower($format[1]);
        $expected = match ($type) { ComicSourceType::CBR => ['rar', 'rar5'], ComicSourceType::CB7 => ['7z'], ComicSourceType::CBT => ['tar'], default => [] };
        if (!in_array($actual, $expected, true)) throw new \RuntimeException('Archive content does not match its extension.');

        return $process->getOutput();
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
        usort($pages, 'strnatcasecmp'); if ($pages === []) throw new \RuntimeException('Archive contains no supported pages.');
        return $pages;
    }
}
