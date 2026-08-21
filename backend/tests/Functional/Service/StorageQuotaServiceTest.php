<?php

declare(strict_types=1);

namespace App\Tests\Functional\Service;

use App\Service\StorageQuotaBusyException;
use App\Service\StorageQuotaExceededException;
use App\Service\StorageQuotaService;
use App\Tests\Factory\UserFactory;
use App\Tests\Functional\AbstractApiTestCase;
use Symfony\Component\Lock\LockFactory;

final class StorageQuotaServiceTest extends AbstractApiTestCase
{
    /**
     * The admin screen reads the effective quota from here rather than the
     * container parameter, so that #64 can make it per-user without the API or
     * the UI learning a second source of truth.
     */
    public function testTheEffectiveQuotaIsTheConfiguredLimit(): void
    {
        $user = UserFactory::createOne()->object();
        $quota = static::getContainer()->get(StorageQuotaService::class);

        self::assertSame(
            (int) static::getContainer()->getParameter('upload_user_quota_bytes'),
            $quota->getQuotaBytes($user)
        );
    }

    /** Reporting the quota changed nothing about being held to it. */
    public function testAdmissionStillRefusesAnUploadPastTheQuota(): void
    {
        $user = UserFactory::createOne()->object();
        $quota = static::getContainer()->get(StorageQuotaService::class);

        $this->expectException(StorageQuotaExceededException::class);
        $quota->acquireAdmission($user, $quota->getQuotaBytes($user) + 1, false);
    }

    public function testSecondAdmissionForSameUserCannotRaceTheFirst(): void
    {
        $user = UserFactory::createOne()->object();
        $quota = static::getContainer()->get(StorageQuotaService::class);
        $lockFactory = static::getContainer()->get(LockFactory::class);
        $first = $lockFactory->createLock($quota->lockResource($user), 300.0, true);
        self::assertTrue($first->acquire());

        try {
            $this->expectException(StorageQuotaBusyException::class);
            $quota->acquireAdmission($user, 1, false);
        } finally {
            $first->release();
        }
    }
}
