<?php

namespace App\ComicSource;

use App\Enum\ComicSourceType;
use Symfony\Component\Process\Process;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

final class SevenZipPageProvider implements ComicPageProviderInterface
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

    /** @return list<string> */
    private function buildIndex(string $path, ComicSourceType $type): array
    {
        $process = new Process(['7z', 'l', '-slt', '--', $path]); $process->setTimeout(self::TIMEOUT); $process->run();
        if (!$process->isSuccessful()) throw new \RuntimeException('Archive inspection failed. Is 7z installed?');
        if (!preg_match('/^Type = (\S+)/mi', $process->getOutput(), $format)) throw new \RuntimeException('Archive format could not be identified.');
        $actual = strtolower($format[1]);
        $expected = match ($type) { ComicSourceType::CBR => ['rar', 'rar5'], ComicSourceType::CB7 => ['7z'], ComicSourceType::CBT => ['tar'], default => [] };
        if (!in_array($actual, $expected, true)) throw new \RuntimeException('Archive content does not match its extension.');
        $pages = []; $entries = 0; $total = 0; $currentPath = null; $inEntries = false;
        foreach (preg_split('/\R/', $process->getOutput()) ?: [] as $line) {
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
