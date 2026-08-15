<?php

namespace App\Service;

use App\Enum\PageVariant;
use Psr\Log\LoggerInterface;

/**
 * Where generated page derivatives live.
 *
 * Split out from the delivery service so that ComicService can drop a comic's
 * pages when its source goes away without depending on the thing that produces
 * them — purging needs only an identifier, while producing needs the whole
 * source pipeline.
 *
 * Nothing here is authoritative. The directory can be deleted at any moment and
 * the only cost is regenerating pages, so every failure is logged and swallowed
 * rather than raised: a cache that cannot be written must not stop a page being
 * served. It also holds no user data in the quota sense — these are rebuildable
 * server files, not the canonical comic.
 *
 * The variant is an enum rather than a string because these values become path
 * segments. Nothing read out of a comic, and nothing out of a query string,
 * reaches a filename here.
 */
final class ComicPageCache
{
    public function __construct(
        private readonly string $directory,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    public function read(int $comicId, int $page, string $fingerprint, PageVariant $variant): ?string
    {
        $path = $this->path($comicId, $page, $fingerprint, $variant, false);
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
    public function write(int $comicId, int $page, string $fingerprint, PageVariant $variant, string $contents): void
    {
        $path = $this->path($comicId, $page, $fingerprint, $variant, true);
        if ($path === null) return;

        $this->writeAtomically($path, $contents, $comicId);
    }

    public function forget(int $comicId, int $page, string $fingerprint, PageVariant $variant): void
    {
        $path = $this->path($comicId, $page, $fingerprint, $variant, false);
        if ($path !== null) @unlink($path);
    }

    /**
     * What is known about the shape of this comic's pages.
     *
     * Kept beside the derivatives and keyed by the same fingerprint, so a
     * replaced source cannot describe its predecessor's pages, and so deleting
     * a comic takes its geometry with it.
     *
     * @return array<int, array{width: int, height: int}> keyed by logical page
     */
    public function readGeometry(int $comicId, string $fingerprint): array
    {
        $path = $this->geometryPath($comicId, $fingerprint, false);
        if ($path === null || !is_file($path)) return [];

        $contents = @file_get_contents($path);
        if (!is_string($contents) || $contents === '') return [];

        $decoded = json_decode($contents, true);
        if (!is_array($decoded)) return [];

        $geometry = [];
        foreach ($decoded as $page => $size) {
            if (!is_array($size)) continue;
            $width = (int) ($size['width'] ?? 0);
            $height = (int) ($size['height'] ?? 0);
            if ((int) $page < 1 || $width < 1 || $height < 1) continue;

            $geometry[(int) $page] = ['width' => $width, 'height' => $height];
        }

        return $geometry;
    }

    /**
     * Add one page's geometry to what is already known.
     *
     * Read, merge, rename. Two requests learning about different pages at the
     * same moment can lose one of the two entries; the loser is re-measured the
     * next time that page is asked about, which is cheaper than holding a lock
     * across the whole file for something nothing depends on being complete.
     */
    public function rememberGeometry(int $comicId, string $fingerprint, PageGeometry $geometry): void
    {
        $path = $this->geometryPath($comicId, $fingerprint, true);
        if ($path === null) return;

        $known = $this->readGeometry($comicId, $fingerprint);
        if (($known[$geometry->page] ?? null) === ['width' => $geometry->width, 'height' => $geometry->height]) return;

        $known[$geometry->page] = ['width' => $geometry->width, 'height' => $geometry->height];
        ksort($known);

        $encoded = json_encode($known);
        if (!is_string($encoded)) return;

        $this->writeAtomically($path, $encoded, $comicId);
    }

    /**
     * Drop every derivative for a comic, so a re-uploaded comic reusing an
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

    private function writeAtomically(string $path, string $contents, int $comicId): void
    {
        $temporary = $path.'.'.bin2hex(random_bytes(6)).'.tmp';

        // A short write, not just an outright failure: a disk that fills
        // mid-write returns a byte count rather than false, and renaming that
        // into place would serve a truncated page for the whole life of this
        // fingerprint.
        $written = @file_put_contents($temporary, $contents, LOCK_EX);
        if ($written !== strlen($contents)) {
            $this->logger?->warning('A comic page derivative could not be cached.', [
                'comic_id' => $comicId,
                'written' => $written === false ? 'failed' : $written.' of '.strlen($contents),
            ]);
            @unlink($temporary);
            return;
        }

        @chmod($temporary, 0644);
        if (!@rename($temporary, $path)) @unlink($temporary);
    }

    private function path(int $comicId, int $page, string $fingerprint, PageVariant $variant, bool $create): ?string
    {
        $directory = $this->comicDirectory($comicId, $create);

        return $directory === null ? null : $directory.'/'.$page.'-'.$variant->value.'-'.$fingerprint.'.webp';
    }

    private function geometryPath(int $comicId, string $fingerprint, bool $create): ?string
    {
        $directory = $this->comicDirectory($comicId, $create);

        return $directory === null ? null : $directory.'/geometry-'.$fingerprint.'.json';
    }

    private function comicDirectory(int $comicId, bool $create): ?string
    {
        $directory = $this->directory.'/'.$comicId;

        if ($create && !is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            $this->logger?->warning('The comic page cache directory could not be created.', ['comic_id' => $comicId]);
            return null;
        }

        return $directory;
    }
}
