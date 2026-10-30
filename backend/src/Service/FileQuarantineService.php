<?php

namespace App\Service;

/**
 * Moves managed uploads out of the public tree, for a limited time.
 *
 * Deleting a comic passes through here only so that a failed transaction can put
 * its files back: the caller purges them after the commit. Orphans swept by
 * ComicCleanupService stay for {@see RETENTION_DAYS}, which is the window for
 * restoring one by hand.
 */
class FileQuarantineService
{
    /**
     * The longest anything stays here: long enough to notice a mistaken orphan
     * sweep, short enough not to be a second copy of somebody's deleted files. The
     * layout does not record why a file arrived, so there is one rule for all of it.
     * docs/storage-quota.md and the admin overview both state this number.
     */
    public const RETENTION_DAYS = 7;

    // An empty directory this recent may be one quarantine() is about to move a
    // file into.
    private const EMPTY_DIRECTORY_GRACE_SECONDS = 3600;

    public function __construct(
        private readonly string $managedDirectory,
        private readonly string $quarantineDirectory
    ) {
    }

    /**
     * @param list<string> $paths
     * @return list<array{originalPath: string, quarantinePath: string}>
     */
    public function quarantine(array $paths): array
    {
        $records = [];

        try {
            foreach (array_unique($paths) as $path) {
                if (is_file($path)) {
                    $records[] = $this->moveToQuarantine($path);
                }
            }
        } catch (\Throwable $exception) {
            $this->restore($records);
            throw $exception;
        }

        return $records;
    }

    /** @param list<array{originalPath: string, quarantinePath: string}> $records */
    public function restore(array $records): void
    {
        foreach (array_reverse($records) as $record) {
            if (!is_file($record['quarantinePath'])) {
                continue;
            }

            $this->ensureDirectory(dirname($record['originalPath']));
            $this->move($record['quarantinePath'], $record['originalPath']);
        }
    }

    /** @param list<array{originalPath: string, quarantinePath: string}> $records */
    public function purge(array $records): void
    {
        foreach ($records as $record) {
            if (is_file($record['quarantinePath']) && !@unlink($record['quarantinePath'])) {
                throw new \RuntimeException(sprintf(
                    'Unable to permanently remove quarantined file "%s".',
                    $record['quarantinePath'],
                ));
            }
        }
    }

    /**
     * Enforce the retention rule on everything here, including what arrived before
     * the rule existed.
     *
     * Age comes from the dated batch directory, because a move keeps a file's own
     * timestamp: a comic uploaded a year ago and quarantined today would otherwise
     * look a year old. A file outside that layout has nothing else to go on.
     *
     * @return array{deleted: int, remaining: int} remaining counts what should have
     *                                             gone and could not, for a later run
     */
    public function removeExpired(\DateTimeImmutable $now = new \DateTimeImmutable()): array
    {
        $counts = ['deleted' => 0, 'remaining' => 0];
        $root = rtrim($this->quarantineDirectory, DIRECTORY_SEPARATOR);
        if (is_dir($root)) {
            $cutoff = $now->modify(sprintf('-%d days', self::RETENTION_DAYS))->getTimestamp();
            $this->sweep($root, $cutoff, $now->getTimestamp() - self::EMPTY_DIRECTORY_GRACE_SECONDS, null, true, $counts);
        }

        return $counts;
    }

    /**
     * @param int|null $batchEnd when this subtree's batch date ended, if it has one
     * @param array{deleted: int, remaining: int} $counts
     */
    private function sweep(string $directory, int $cutoff, int $graceCutoff, ?int $batchEnd, bool $isRoot, array &$counts): void
    {
        $entries = @scandir($directory);
        if ($entries === false) {
            ++$counts['remaining'];

            return;
        }

        foreach ($entries as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }

            $path = $directory . DIRECTORY_SEPARATOR . $name;
            $entryBatchEnd = $isRoot ? $this->batchEnd($name) : $batchEnd;

            if (is_dir($path) && !is_link($path)) {
                $this->sweep($path, $cutoff, $graceCutoff, $entryBatchEnd, false, $counts);
                $expired = $entryBatchEnd !== null && $entryBatchEnd <= $cutoff;
                if ($expired || (int) @filemtime($path) <= $graceCutoff) {
                    // Only ever succeeds on an empty directory. A purged comic
                    // leaves these behind, and their names hold its ids.
                    @rmdir($path);
                }
                continue;
            }

            if (($entryBatchEnd ?? (int) @filemtime($path)) > $cutoff) {
                continue;
            }

            if (@unlink($path)) {
                ++$counts['deleted'];
            } else {
                ++$counts['remaining'];
            }
        }
    }

    /**
     * The end of a batch's date rather than its start, so a file quarantined late
     * in the day still gets the whole retention window.
     */
    private function batchEnd(string $name): ?int
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $name);
        if ($date === false || $date->format('Y-m-d') !== $name) {
            return null;
        }

        return $date->modify('+1 day')->getTimestamp();
    }

    /** @return array{originalPath: string, quarantinePath: string} */
    private function moveToQuarantine(string $path): array
    {
        $managedRoot = realpath($this->managedDirectory);
        $source = realpath($path);
        if ($managedRoot === false || $source === false) {
            throw new \RuntimeException('Unable to resolve the managed upload path.');
        }

        $managedPrefix = rtrim($managedRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        if (!str_starts_with($source, $managedPrefix)) {
            throw new \RuntimeException('Refusing to quarantine a file outside the managed uploads directory.');
        }

        $relativePath = substr($source, strlen($managedPrefix));
        $batch = (new \DateTimeImmutable())->format('Y-m-d') . '/' . bin2hex(random_bytes(8));
        $destination = rtrim($this->quarantineDirectory, DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR . $batch . DIRECTORY_SEPARATOR . $relativePath;

        $this->ensureDirectory(dirname($destination));
        $this->move($source, $destination);

        return ['originalPath' => $source, 'quarantinePath' => $destination];
    }

    private function move(string $source, string $destination): void
    {
        if (file_exists($destination)) {
            throw new \RuntimeException(sprintf('Refusing to overwrite existing file "%s".', $destination));
        }

        if (@rename($source, $destination)) {
            return;
        }

        if (!copy($source, $destination)) {
            throw new \RuntimeException(sprintf('Unable to quarantine file "%s".', $source));
        }

        if (!unlink($source)) {
            @unlink($destination);
            throw new \RuntimeException(sprintf('Unable to remove original file "%s" after copying it.', $source));
        }
    }

    private function ensureDirectory(string $directory): void
    {
        if (is_dir($directory)) {
            return;
        }

        // Group-writable whatever the web server's umask: the retention sweep runs
        // from cron, usually as the deploy user, who only shares a group with
        // PHP-FPM, and removing a file takes write permission on its directory.
        $previousUmask = umask(0007);
        try {
            if (!mkdir($directory, 0770, true) && !is_dir($directory)) {
                throw new \RuntimeException(sprintf('Unable to create directory "%s".', $directory));
            }
        } finally {
            umask($previousUmask);
        }
    }
}
