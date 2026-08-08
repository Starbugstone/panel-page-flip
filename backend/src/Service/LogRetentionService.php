<?php

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Deletes expired daily log files, and the month and year folders they leave
 * behind.
 *
 * Retention here is a real deletion by a real command, not a `max_files`
 * setting that nothing enforces. The three streams have deliberately different
 * lifetimes: normal operational logs are noise after a month, while a security
 * or audit record is often only asked for once somebody notices something
 * months later.
 *
 * Only the log files are in scope. The acknowledgement timestamps from the 18+
 * sharing gate live on the `ComicShare` row and are not logs; nothing here can
 * reach them, which is the reason those are the canonical evidence and the log
 * is not.
 */
final class LogRetentionService
{
    /** `2026-08-08.log`, and nothing else in the folder. */
    private const FILENAME_PATTERN = '/^(\d{4})-(\d{2})-(\d{2})\.log$/';

    public function __construct(
        #[Autowire('%kernel.logs_dir%')]
        private readonly string $logsDir,
        #[Autowire('%env(int:APP_LOG_RETENTION_DAYS)%')]
        private readonly int $appRetentionDays,
        #[Autowire('%env(int:SECURITY_LOG_RETENTION_DAYS)%')]
        private readonly int $securityRetentionDays,
        #[Autowire('%env(int:AUDIT_LOG_RETENTION_DAYS)%')]
        private readonly int $auditRetentionDays,
    ) {
    }

    /**
     * @return array<string, array{directory: string, retentionDays: int, filesDeleted: int, directoriesRemoved: int, errors: int}>
     */
    public function clean(bool $dryRun = false, \DateTimeImmutable $now = new \DateTimeImmutable()): array
    {
        $results = [];

        foreach ($this->streams() as $name => [$directory, $retentionDays]) {
            $results[$name] = $this->cleanStream($directory, $retentionDays, $dryRun, $now)
                + ['directory' => $directory, 'retentionDays' => $retentionDays];
        }

        return $results;
    }

    /**
     * @return array<string, array{0: string, 1: int}>
     */
    private function streams(): array
    {
        $root = rtrim($this->logsDir, '/\\');

        return [
            'app' => [$root . '/app', $this->appRetentionDays],
            'security' => [$root . '/security', $this->securityRetentionDays],
            'audit' => [$root . '/audit', $this->auditRetentionDays],
        ];
    }

    /**
     * @return array{filesDeleted: int, directoriesRemoved: int, errors: int}
     */
    private function cleanStream(string $directory, int $retentionDays, bool $dryRun, \DateTimeImmutable $now): array
    {
        $counts = ['filesDeleted' => 0, 'directoriesRemoved' => 0, 'errors' => 0];

        if (!is_dir($directory)) {
            return $counts;
        }

        // Midnight, so a file is kept for its whole last day rather than for a
        // number of hours that depends on when the cleanup happens to run.
        $cutoff = $now->setTime(0, 0)->modify(sprintf('-%d days', max(1, $retentionDays)));

        foreach ($this->logFiles($directory) as $path) {
            $day = $this->dayOf($path);
            if ($day === null || $day >= $cutoff) {
                continue;
            }

            if ($dryRun) {
                ++$counts['filesDeleted'];
                continue;
            }

            if (@unlink($path)) {
                ++$counts['filesDeleted'];
            } else {
                ++$counts['errors'];
            }
        }

        // After the files, so a month folder emptied by this run is collected by
        // it rather than surviving until the next one. Skipped entirely on a dry
        // run, where nothing was actually removed.
        if (!$dryRun) {
            $counts['directoriesRemoved'] = $this->removeEmptyDirectories($directory);
        }

        return $counts;
    }

    /** @return iterable<string> */
    private function logFiles(string $directory): iterable
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            if ($file->isFile() && preg_match(self::FILENAME_PATTERN, $file->getFilename()) === 1) {
                yield $file->getPathname();
            }
        }
    }

    /**
     * The day a file covers, read from its name.
     *
     * The name and not the mtime: a file touched by a late flush, a copy or a
     * backup restore would otherwise look younger than the records in it, and
     * retention would quietly stop meaning what it says.
     */
    private function dayOf(string $path): ?\DateTimeImmutable
    {
        if (preg_match(self::FILENAME_PATTERN, basename($path), $matches) !== 1) {
            return null;
        }

        $day = \DateTimeImmutable::createFromFormat(
            'Y-m-d H:i:s',
            sprintf('%s-%s-%s 00:00:00', $matches[1], $matches[2], $matches[3])
        );

        return $day === false ? null : $day;
    }

    /** Depth-first, so a year folder emptied by its last month goes too. */
    private function removeEmptyDirectories(string $directory): int
    {
        $removed = 0;

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $entry) {
            /** @var \SplFileInfo $entry */
            if (!$entry->isDir()) {
                continue;
            }

            // Not the stream root itself: the handler expects it to exist, and
            // an empty log directory is a normal state, not a leftover.
            if (@rmdir($entry->getPathname())) {
                ++$removed;
            }
        }

        return $removed;
    }
}
