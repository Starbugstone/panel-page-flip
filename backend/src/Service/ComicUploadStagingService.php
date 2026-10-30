<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\LockInterface;

/**
 * Where chunked uploads wait to become comics, and how much room they may take.
 *
 * Staged files are on the disk long before they are a Comic row, so nothing else
 * counts them. The limits here are measured from the files themselves rather than
 * from any upload's metadata: the disk is what is being protected, and it cannot
 * be out of step with itself after a crashed request.
 *
 * The directory is under var/ rather than the system temp dir because the expiry
 * sweep runs from cron. PHP-FPM and the CLI do not have to agree on a temp dir (a
 * private /tmp or a per-SAPI sys_temp_dir both split them), and a sweep looking in
 * the wrong one would report nothing to do for ever.
 *
 * Lock order is always upload, then account. The sweep only ever try-locks.
 *
 * See docs/storage-quota.md.
 */
final class ComicUploadStagingService
{
    private const LOCK_FILE = '.chunk.lock';
    private const METADATA_FILE = 'metadata.json';
    private const FINALIZING_MARKER = '.finalizing';

    // Held across one quota lookup and one chunk move. Comfortably beyond both,
    // and irrelevant to flock, which releases with the process.
    private const ACCOUNT_LOCK_TTL_SECONDS = 60.0;

    public function __construct(
        private readonly string $stagingDirectory,
        private readonly int $uploadMaxStagedBytes,
        private readonly int $uploadMaxActiveUploads,
        private readonly int $uploadStagingIdleSeconds,
        private readonly StorageQuotaService $storageQuota,
        private readonly LockFactory $lockFactory,
        private readonly LoggerInterface $logger,
    ) {
    }

    /** $fileId must already be validated: it comes off the request. */
    public function uploadDirectory(User $user, string $fileId): string
    {
        return $this->stagingDirectory . '/' . $user->getId() . '/' . $fileId;
    }

    public function ensureDirectory(string $directory): void
    {
        if (is_dir($directory)) {
            return;
        }

        // Group-writable whatever the web server's umask: the cron job is usually
        // the deploy user, who only shares a group with PHP-FPM, and removing a
        // file takes write permission on its directory.
        $previousUmask = umask(0007);
        try {
            if (!mkdir($directory, 0770, true) && !is_dir($directory)) {
                throw new \RuntimeException('Failed to create the upload staging directory.');
            }
        } finally {
            umask($previousUmask);
        }
    }

    /**
     * Take the exclusive lock for one staged upload.
     *
     * A dedicated lock file rather than metadata.json itself: the metadata is
     * rewritten with file_put_contents, which truncates, and a lock held on a
     * handle to a file being replaced under it protects nothing.
     *
     * Blocking, not LOCK_NB. The chunks of one upload are meant to be admitted
     * one at a time, so a request that arrives mid-write should wait its turn
     * rather than be told to try again.
     *
     * @return resource
     */
    public function lockUpload(string $directory)
    {
        $handle = @fopen($directory . '/' . self::LOCK_FILE, 'c');
        if ($handle === false) {
            throw new \RuntimeException('Failed to open the upload lock.');
        }

        if (!flock($handle, LOCK_EX)) {
            fclose($handle);

            throw new \RuntimeException('Failed to acquire the upload lock.');
        }

        return $handle;
    }

    /** @param resource $handle */
    public function unlockUpload($handle): void
    {
        flock($handle, LOCK_UN);
        fclose($handle);
    }

    /**
     * Serialises one account's admissions, across all of its uploads. Without it
     * two parallel uploads both measure the same free space and both take it.
     *
     * Not StorageQuotaService's lock: that one is held for as long as a comic takes
     * to validate, and every chunk of every other upload would stall behind it.
     */
    public function lockAccount(User $user): LockInterface
    {
        $lock = $this->lockFactory->createLock(
            'upload-staging:user:' . $user->getId(),
            self::ACCOUNT_LOCK_TTL_SECONDS
        );
        $lock->acquire(true);

        return $lock;
    }

    /**
     * Call under {@see lockAccount}.
     *
     * The client cannot resume an upload, so a cancelled one is simply left behind.
     * Clearing the account's own expired uploads first means somebody coming back
     * after that is not refused over their own leftovers until the next cron run.
     *
     * @throws StagedUploadRefusedException
     */
    public function admitNewUpload(User $user, int $now): void
    {
        $this->expireInAccountDirectory($this->stagingDirectory . '/' . $user->getId(), $now);

        $usage = $this->measure($user);
        if ($usage['uploads'] >= $this->uploadMaxActiveUploads) {
            throw new StagedUploadRefusedException(
                'Too many uploads in progress. Wait for one to finish and try again. Cancelled uploads are released automatically.',
                Response::HTTP_TOO_MANY_REQUESTS
            );
        }

        // An upload has at least one byte, so it needs room for at least that.
        $this->assertRoom($user, $usage, 1);
    }

    /**
     * Call under {@see lockAccount}, and keep holding it until the chunk is on
     * disk: that is what makes this measurement a reservation.
     *
     * @param int $replacedBytes what a resent chunk frees by replacing its old copy
     *
     * @throws StagedUploadRefusedException
     */
    public function admitChunk(User $user, int $incomingBytes, int $replacedBytes): void
    {
        $this->assertRoom($user, $this->measure($user), $incomingBytes - $replacedBytes);
    }

    /**
     * From here until the upload's directory is removed, ComicService's own
     * admission check speaks for these bytes, so the quota check stops counting
     * them. Counting both would refuse a parallel upload in the moment between
     * this one's row committing and its staged copy being deleted. The disk limit
     * keeps counting them, because they are still on the disk.
     */
    public function markFinalizing(string $directory): void
    {
        @touch($directory . '/' . self::FINALIZING_MARKER);
    }

    public function remove(string $directory): void
    {
        $this->removeTree($directory);
        clearstatcache();
        if (is_dir($directory)) {
            $this->logger->error('A staged upload could not be removed.', ['directory' => basename($directory)]);
        }
    }

    /**
     * Everything an erased account still has staged. The chunks are that person's
     * files, and the sweep would only reach them after the idle window and a cron run.
     */
    public function removeForAccount(int $userId): void
    {
        $this->remove($this->stagingDirectory . '/' . $userId);
    }

    /**
     * The scheduled sweep, across every account.
     *
     * @return array{expired: int, remaining: int}
     */
    public function expireAbandoned(int $now): array
    {
        $counts = ['expired' => 0, 'remaining' => 0];
        foreach ($this->subdirectories($this->stagingDirectory) as $accountDirectory) {
            $result = $this->expireInAccountDirectory($accountDirectory, $now);
            $counts['expired'] += $result['expired'];
            $counts['remaining'] += $result['remaining'];
            // Succeeds only once the account has nothing staged.
            @rmdir($accountDirectory);
        }

        return $counts;
    }

    /** @return array{expired: int, remaining: int} */
    private function expireInAccountDirectory(string $accountDirectory, int $now): array
    {
        $counts = ['expired' => 0, 'remaining' => 0];
        $cutoff = $now - $this->uploadStagingIdleSeconds;

        foreach ($this->subdirectories($accountDirectory) as $directory) {
            if ($this->lastActivity($directory) >= $cutoff) {
                continue;
            }

            // Read-only is enough for flock, and the only way in when cron runs as
            // a different user from the web server that created the lock file.
            $lockPath = $directory . '/' . self::LOCK_FILE;
            $lock = @fopen($lockPath, 'r') ?: @fopen($lockPath, 'c');
            if ($lock === false) {
                ++$counts['remaining'];
                $this->logger->error('An abandoned staged upload could not be locked for removal.', ['directory' => basename($directory)]);
                continue;
            }

            // Held means a request is working on it right now, however quiet it
            // looked a moment ago. It is not abandoned.
            if (!flock($lock, LOCK_EX | LOCK_NB)) {
                fclose($lock);
                continue;
            }

            try {
                // Only the metadata can be asked again: opening the lock above may
                // have just created it, which counts as a change to the directory.
                // Everything that happened since the first check rewrote this.
                clearstatcache();
                if ((int) @filemtime($directory . '/' . self::METADATA_FILE) >= $cutoff) {
                    continue;
                }

                $this->remove($directory);
                clearstatcache();
                if (is_dir($directory)) {
                    ++$counts['remaining'];
                } else {
                    ++$counts['expired'];
                }
            } finally {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }

        return $counts;
    }

    /**
     * @param array{uploads: int, bytes: int, quotaBytes: int} $usage
     * @param int $growth how much larger staging would get; negative for a smaller resend
     */
    private function assertRoom(User $user, array $usage, int $growth): void
    {
        if ($growth <= 0) {
            return;
        }

        if ($usage['bytes'] + $growth > $this->uploadMaxStagedBytes) {
            throw new StagedUploadRefusedException(
                'Too much unfinished upload data on this account. Wait for an upload to finish and try again. Cancelled uploads are released automatically.',
                Response::HTTP_REQUEST_ENTITY_TOO_LARGE
            );
        }

        if (!$this->storageQuota->wouldExceedQuota($user, $usage['quotaBytes'] + $growth)) {
            return;
        }

        throw new StagedUploadRefusedException(
            $this->storageQuota->wouldExceedQuota($user, $growth)
                ? 'User storage quota exceeded.'
                : 'User storage quota exceeded once unfinished uploads are counted. Cancelled uploads are released automatically.',
            Response::HTTP_REQUEST_ENTITY_TOO_LARGE
        );
    }

    /** @return array{uploads: int, bytes: int, quotaBytes: int} */
    private function measure(User $user): array
    {
        clearstatcache();
        $usage = ['uploads' => 0, 'bytes' => 0, 'quotaBytes' => 0];

        foreach ($this->subdirectories($this->stagingDirectory . '/' . $user->getId()) as $directory) {
            // A directory with no metadata is an init that has not got this far
            // yet, or one that was refused. Neither is an upload in progress.
            if (is_file($directory . '/' . self::METADATA_FILE)) {
                ++$usage['uploads'];
            }

            $countsTowardsQuota = !is_file($directory . '/' . self::FINALIZING_MARKER);
            foreach (@scandir($directory) ?: [] as $name) {
                if ($name === '.' || $name === '..') {
                    continue;
                }

                $bytes = (int) @filesize($directory . '/' . $name);
                $usage['bytes'] += $bytes;
                // The quota is about the comic. Our own bookkeeping beside it must
                // not refuse a file that exactly fits what the account has left.
                if ($countsTowardsQuota && $name !== self::METADATA_FILE && $name[0] !== '.') {
                    $usage['quotaBytes'] += $bytes;
                }
            }
        }

        return $usage;
    }

    private function lastActivity(string $directory): int
    {
        // Adding or replacing a chunk changes the directory, and every chunk
        // rewrites the metadata in place.
        return max((int) @filemtime($directory), (int) @filemtime($directory . '/' . self::METADATA_FILE));
    }

    /** @return list<string> */
    private function subdirectories(string $directory): array
    {
        $found = [];
        foreach (@scandir($directory) ?: [] as $name) {
            $path = $directory . '/' . $name;
            if ($name !== '.' && $name !== '..' && is_dir($path) && !is_link($path)) {
                $found[] = $path;
            }
        }

        return $found;
    }

    private function removeTree(string $directory): void
    {
        foreach (@scandir($directory) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }

            $path = $directory . '/' . $name;
            if (is_dir($path) && !is_link($path)) {
                $this->removeTree($path);
            } else {
                @unlink($path);
            }
        }

        @rmdir($directory);
    }
}
