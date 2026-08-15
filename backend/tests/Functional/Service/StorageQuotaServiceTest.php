<?php

declare(strict_types=1);

namespace App\Tests\Functional\Service;

use App\Service\StorageQuotaBusyException;
use App\Service\StorageQuotaService;
use App\Tests\Factory\UserFactory;
use App\Tests\Functional\AbstractApiTestCase;
use Symfony\Component\Lock\LockFactory;

final class StorageQuotaServiceTest extends AbstractApiTestCase
{
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
