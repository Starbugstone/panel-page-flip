<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Comic;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\LockInterface;

final class StorageQuotaService
{
    // Keep the admission lock comfortably beyond the configured 300-second PHP
    // execution ceiling so a long validation/finalization cannot lose serialization
    // immediately before committing its storage usage.
    private const LOCK_TTL_SECONDS = 900.0;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly LockFactory $lockFactory,
        private readonly int $uploadUserQuotaBytes,
    ) {
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
        return $this->getUserStorageBytes($user) + $additionalBytes > $this->getQuotaBytes($user);
    }

    /**
     * The quota this account is actually held to.
     *
     * Every user gets the same configured limit today, which is why the
     * argument looks unused. It is the seam #64 needs: resolving a per-user
     * override happens here, and callers that already ask the service rather
     * than reading `%upload_user_quota_bytes%` will not have to change.
     */
    public function getQuotaBytes(User $user): int
    {
        return $this->uploadUserQuotaBytes;
    }

    public function getUserStorageBytes(User $user): int
    {
        return (int) $this->entityManager->createQueryBuilder()
            ->select('COALESCE(SUM(c.fileSize), 0)')
            ->from(Comic::class, 'c')
            ->where('c.owner = :owner')
            ->setParameter('owner', $user)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function lockResource(User $user): string
    {
        if ($user->getId() === null) {
            throw new \InvalidArgumentException('Storage quota locks require a persisted user.');
        }

        return 'storage-quota:user:' . $user->getId();
    }
}
