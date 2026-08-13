<?php

namespace App\ComicSource;

use App\Enum\ComicSourceType;
use App\Metadata\ComicInfoParser;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use ZipArchive;

final class ZipPageProvider implements ComicPageProviderInterface, ComicInfoSourceInterface
{
    /** @var array<string, list<string>> */
    private array $indexes = [];

    public function __construct(private readonly ?CacheInterface $pageIndexCache = null)
    {
    }

    public function supports(ComicSourceType $type): bool { return $type === ComicSourceType::CBZ; }

    public function inspect(string $sourcePath, ComicSourceType $type): ComicSourceInfo
    {
        return new ComicSourceInfo(count($this->pageIndex($sourcePath)));
    }

    public function readPage(string $sourcePath, ComicSourceType $type, int $page): PageResult
    {
        $zip = new ZipArchive();
        if ($zip->open($sourcePath) !== true) throw new \RuntimeException('Failed to open ZIP source.');

        try {
            $key = $this->sourceKey($sourcePath);
            $pages = $this->indexes[$key] ??= $this->cachedPageIndex($sourcePath, $zip);
            if ($page < 1 || !isset($pages[$page - 1])) throw new \OutOfRangeException('Page not found.');
            $content = $zip->getFromName($pages[$page - 1]);
            if ($content === false) throw new \RuntimeException('Failed to read page.');

            return PageResult::fromImageContent($content);
        } finally {
            $zip->close();
        }
    }

    public function readComicInfoXml(string $sourcePath, ComicSourceType $type): ?string
    {
        $zip = new ZipArchive();
        if ($zip->open($sourcePath) !== true) return null;

        try {
            for ($i = 0; $i < min($zip->numFiles, ComicSourceLimits::MAX_ENTRIES); ++$i) {
                $name = $zip->statIndex($i)['name'] ?? '';
                if (!self::isComicInfoEntry($name)) continue;

                $xml = $zip->getFromIndex($i, ComicSourceLimits::MAX_METADATA_BYTES);

                return is_string($xml) && $xml !== '' ? $xml : null;
            }
        } finally {
            $zip->close();
        }

        return null;
    }

    /** @return list<string> */
    public function pageIndex(string $sourcePath): array
    {
        $key = $this->sourceKey($sourcePath);
        if (isset($this->indexes[$key])) return $this->indexes[$key];

        $zip = new ZipArchive();
        if ($zip->open($sourcePath) !== true) throw new \RuntimeException('Failed to open ZIP source.');

        try {
            return $this->indexes[$key] = $this->cachedPageIndex($sourcePath, $zip);
        } finally {
            $zip->close();
        }
    }

    /** @return list<string> */
    private function cachedPageIndex(string $sourcePath, ZipArchive $zip): array
    {
        if ($this->pageIndexCache === null) return $this->buildPageIndex($zip);

        $cacheKey = 'comic_source.zip.'.hash('xxh128', $this->sourceKey($sourcePath));
        return $this->pageIndexCache->get($cacheKey, function (ItemInterface $item) use ($zip): array {
            $item->expiresAfter(86_400);
            return $this->buildPageIndex($zip);
        });
    }

    /** @return list<string> */
    private function buildPageIndex(ZipArchive $zip): array
    {
        if ($zip->numFiles > ComicSourceLimits::MAX_ENTRIES) throw new \RuntimeException('Archive has too many entries.');
        $pages = []; $total = 0;
        for ($i = 0; $i < $zip->numFiles; ++$i) {
            $stat = $zip->statIndex($i); $name = $stat['name'] ?? '';
            $entrySize = (int) ($stat['size'] ?? 0);
            $total += $entrySize;
            if ($total > ComicSourceLimits::MAX_UNCOMPRESSED_BYTES) throw new \RuntimeException('Archive expands beyond the safety limit.');
            if (self::isSafeImage($name) && $entrySize > ComicSourceLimits::MAX_PAGE_BYTES) throw new \RuntimeException('Archive contains an oversized page.');
            if (self::isSafeImage($name)) $pages[] = $name;
        }
        usort($pages, 'strnatcasecmp');
        if ($pages === []) throw new \RuntimeException('Archive contains no supported pages.');
        return $pages;
    }

    private function sourceKey(string $sourcePath): string
    {
        return $sourcePath.'|'.(@filemtime($sourcePath) ?: 0).'|'.(@filesize($sourcePath) ?: 0);
    }

    /** Only at the archive root: a nested ComicInfo.xml describes something else. */
    public static function isComicInfoEntry(string $name): bool
    {
        $normal = str_replace('\\', '/', $name);

        return !str_contains($normal, '/') && strcasecmp($normal, ComicInfoParser::ENTRY_NAME) === 0;
    }

    public static function isSafeImage(string $name): bool
    {
        $normal = str_replace('\\', '/', $name);
        if ($normal === '' || str_starts_with($normal, '/') || str_starts_with($normal, '//')
            || preg_match('/^[a-z]:\//i', $normal) || str_contains($normal, "\0")
            || preg_match('#(^|/)\.\.(/|$)#', $normal)
            || str_starts_with($normal, '__MACOSX/') || str_starts_with(basename($normal), '.')) return false;
        return in_array(strtolower(pathinfo($normal, PATHINFO_EXTENSION)), ['jpg','jpeg','png','gif','webp'], true);
    }
}
