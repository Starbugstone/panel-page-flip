<?php

namespace App\Service;

use Psr\Log\LoggerInterface;

/**
 * Where generated page images live.
 *
 * Split out from the delivery service so that ComicService can drop a comic's
 * pages when its source goes away without depending on the thing that produces
 * them — purging needs only an identifier, while producing needs the whole
 * source pipeline.
 *
 * Nothing here is authoritative. The directory can be deleted at any moment and
 * the only cost is regenerating pages, so every failure is logged and swallowed
 * rather than raised: a cache that cannot be written must not stop a page being
 * served.
 */
final class ComicPageCache
{
    public function __construct(
        private readonly string $directory,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    public function read(int $comicId, int $page, string $fingerprint): ?string
    {
        $path = $this->path($comicId, $page, $fingerprint, false);
        if ($path === null || !is_file($path)) return null;

        $contents = @file_get_contents($path);
        if (!is_string($contents) || $contents === '') {
            @unlink($path);
            return null;
        }

        return $contents;
    }

    /**
     * Write through a temporary file in the same directory, so a reader can
     * never be handed a half-written page and two concurrent generations of
     * the same page cannot interleave.
     */
    public function write(int $comicId, int $page, string $fingerprint, string $contents): void
    {
        $path = $this->path($comicId, $page, $fingerprint, true);
        if ($path === null) return;

        $temporary = $path.'.'.bin2hex(random_bytes(6)).'.tmp';
        if (@file_put_contents($temporary, $contents, LOCK_EX) === false) {
            $this->logger?->warning('A comic page could not be cached.', ['comic_id' => $comicId]);
            return;
        }

        @chmod($temporary, 0644);
        if (!@rename($temporary, $path)) @unlink($temporary);
    }

    public function forget(int $comicId, int $page, string $fingerprint): void
    {
        $path = $this->path($comicId, $page, $fingerprint, false);
        if ($path !== null) @unlink($path);
    }

    /**
     * Drop every cached page for a comic, so a re-uploaded comic reusing an
     * identifier cannot inherit the previous one's pages.
     */
    public function purge(int $comicId): void
    {
        $directory = $this->directory.'/'.$comicId;
        if (!is_dir($directory)) return;

        foreach (glob($directory.'/*') ?: [] as $file) @unlink($file);
        @rmdir($directory);
    }

    private function path(int $comicId, int $page, string $fingerprint, bool $create): ?string
    {
        $directory = $this->directory.'/'.$comicId;

        if ($create && !is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            $this->logger?->warning('The comic page cache directory could not be created.', ['comic_id' => $comicId]);
            return null;
        }

        return $directory.'/'.$page.'-'.$fingerprint.'.webp';
    }
}
