<?php

namespace App\ComicSource;

use App\Enum\ComicSourceType;
use ZipArchive;

final class ZipPageProvider implements ComicPageProviderInterface
{
    private const MAX_ENTRIES = 10000;
    private const MAX_UNCOMPRESSED_BYTES = 2147483648;

    public function supports(ComicSourceType $type): bool { return $type === ComicSourceType::CBZ; }

    public function inspect(string $sourcePath, ComicSourceType $type): ComicSourceInfo
    {
        return new ComicSourceInfo(count($this->pageIndex($sourcePath)));
    }

    public function readPage(string $sourcePath, ComicSourceType $type, int $page): PageResult
    {
        $pages = $this->pageIndex($sourcePath);
        if ($page < 1 || !isset($pages[$page - 1])) throw new \OutOfRangeException('Page not found.');
        $zip = new ZipArchive();
        if ($zip->open($sourcePath) !== true) throw new \RuntimeException('Failed to open ZIP source.');
        $content = $zip->getFromName($pages[$page - 1]);
        $zip->close();
        if ($content === false) throw new \RuntimeException('Failed to read page.');
        return new PageResult($content, self::imageMime($pages[$page - 1]));
    }

    /** @return list<string> */
    public function pageIndex(string $sourcePath): array
    {
        $zip = new ZipArchive();
        if ($zip->open($sourcePath) !== true) throw new \RuntimeException('Failed to open ZIP source.');
        if ($zip->numFiles > self::MAX_ENTRIES) { $zip->close(); throw new \RuntimeException('Archive has too many entries.'); }
        $pages = []; $total = 0;
        for ($i = 0; $i < $zip->numFiles; ++$i) {
            $stat = $zip->statIndex($i); $name = $stat['name'] ?? '';
            $total += (int) ($stat['size'] ?? 0);
            if ($total > self::MAX_UNCOMPRESSED_BYTES) { $zip->close(); throw new \RuntimeException('Archive expands beyond the safety limit.'); }
            if (self::isSafeImage($name)) $pages[] = $name;
        }
        $zip->close(); usort($pages, 'strnatcasecmp');
        if ($pages === []) throw new \RuntimeException('Archive contains no supported pages.');
        return $pages;
    }

    public static function isSafeImage(string $name): bool
    {
        $normal = str_replace('\\', '/', $name);
        if ($normal === '' || str_starts_with($normal, '/') || preg_match('#(^|/)\.\.(/|$)#', $normal)
            || str_starts_with($normal, '__MACOSX/') || str_starts_with(basename($normal), '.')) return false;
        return in_array(strtolower(pathinfo($normal, PATHINFO_EXTENSION)), ['jpg','jpeg','png','gif','webp'], true);
    }

    public static function imageMime(string $name): string
    {
        return match (strtolower(pathinfo($name, PATHINFO_EXTENSION))) {
            'jpg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp',
            default => 'application/octet-stream',
        };
    }
}
