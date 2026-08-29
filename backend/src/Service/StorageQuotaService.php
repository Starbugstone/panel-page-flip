<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use App\Repository\ComicRepository;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\LockInterface;

final class StorageQuotaService
{
    /** Largest integer JavaScript clients can receive without losing bytes. */
    public const MAX_QUOTA_BYTES = 9_007_199_254_740_991;

    // Keep the admission lock comfortably beyond the configured 300-second PHP
    // execution ceiling so a long validation/finalization cannot lose serialization
    // immediately before committing its storage usage.
    private const LOCK_TTL_SECONDS = 900.0;

    public function __construct(
        private readonly ComicRepository $comics,
        private readonly LockFactory $lockFactory,
        private readonly int $uploadUserQuotaBytes,
    ) {
        if ($this->uploadUserQuotaBytes < 0 || $this->uploadUserQuotaBytes > self::MAX_QUOTA_BYTES) {
            throw new \InvalidArgumentException(sprintf(
                'UPLOAD_USER_QUOTA_BYTES must be between 0 (unlimited) and %d.',
                self::MAX_QUOTA_BYTES
            ));
        }
    }

    public function acquireAdmission(User $user, int $additionalBytes, bool $blocking = true): LockInterface
    {
        if ($additionalBytes < 0 || $user->getId() === null) {
            throw new \InvalidArgumentException('Storage admission requires a persisted user and a non-negative size.');
        }

        $lock = $this->lockFactory->createLock($this->lockResource($user), self::LOCK_TTL_SECONDS, true);
        if (!$lock->acquire($blocking)) {
            throw new StorageQuotaBusyException('Another storage operation is already in progress for this account.');
        }

        if ($this->wouldExceedQuota($user, $additionalBytes)) {
            $lock->release();
            throw new StorageQuotaExceededException('User storage quota exceeded.');
        }

        return $lock;
    }

    public function wouldExceedQuota(User $user, int $additionalBytes): bool
    {
        $quotaBytes = $this->getQuotaBytes($user);

        return $quotaBytes > 0
            && $this->getUserStorageBytes($user) + $additionalBytes > $quotaBytes;
    }

    /**
     * The quota this account is actually held to.
     *
     * A stored override wins; null inherits the installation's environment
     * default. Zero deliberately means unlimited in either place. Callers use
     * this resolver so reporting and admission always agree.
     */
    public function getQuotaBytes(User $user): int
    {
        return $user->getStorageQuotaOverrideBytes() ?? $this->uploadUserQuotaBytes;
    }

    public function getDefaultQuotaBytes(): int
    {
        return $this->uploadUserQuotaBytes;
    }

    public function getUserStorageBytes(User $user): int
    {
        $ownerId = $user->getId();
        // An account that was never persisted owns no comics, so there is no row
        // to sum and no quota question to answer.
        if ($ownerId === null) {
            return 0;
        }

        return $this->comics->getStorageBytesForOwner($ownerId);
    }

    public function lockResource(User $user): string
    {
        if ($user->getId() === null) {
            throw new \InvalidArgumentException('Storage quota locks require a persisted user.');
        }

        return 'storage-quota:user:' . $user->getId();
    }
}
