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

        // A short write, not just an outright failure: a disk that fills
        // mid-write returns a byte count rather than false, and renaming that
        // into place would serve a truncated page for the whole life of this
        // fingerprint.
        $written = @file_put_contents($temporary, $contents, LOCK_EX);
        if ($written !== strlen($contents)) {
            $this->logger?->warning('A comic page could not be cached.', [
                'comic_id' => $comicId,
                'written' => $written === false ? 'failed' : $written.' of '.strlen($contents),
            ]);
            @unlink($temporary);
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

    /**
     * Drop pages nobody has read since $before, and every page belonging to a
     * comic that no longer exists.
     *
     * Reading a page touches its file, so a library in regular use keeps its
     * pages and only the ones nobody opens age out.
     *
     * @param array<int, mixed> $knownComicIds comic ids that still exist, keyed by id
     *
     * @return array{stale: int, orphans: int, bytes: int}
     */
    public function prune(?\DateTimeImmutable $before, array $knownComicIds, bool $dryRun = false): array
    {
        $result = ['stale' => 0, 'orphans' => 0, 'bytes' => 0];
        if (!is_dir($this->directory)) return $result;

        $threshold = $before?->getTimestamp();

        foreach (glob($this->directory.'/*', GLOB_ONLYDIR) ?: [] as $comicDirectory) {
            $name = basename($comicDirectory);
            if (!ctype_digit($name)) continue;

            $orphaned = !array_key_exists((int) $name, $knownComicIds);
            $remaining = 0;

            foreach (glob($comicDirectory.'/*') ?: [] as $file) {
                if (!is_file($file)) continue;

                // Access time where the filesystem records it, modification
                // time otherwise: plenty of servers mount with noatime.
                $touched = max((int) @fileatime($file), (int) @filemtime($file));
                $stale = $threshold !== null && $touched < $threshold;

                if (!$orphaned && !$stale) {
                    ++$remaining;
                    continue;
                }

                $result['bytes'] += (int) @filesize($file);
                $orphaned ? ++$result['orphans'] : ++$result['stale'];
                if (!$dryRun) @unlink($file);
            }

            if (!$dryRun && $remaining === 0) @rmdir($comicDirectory);
        }

        return $result;
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
