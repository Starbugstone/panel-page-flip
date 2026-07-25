<?php

namespace App\Tests\Functional\Controller;

use App\Tests\Factory\ComicFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Functional\AbstractApiTestCase;

class AdminControllerTest extends AbstractApiTestCase
{
    public function testRegularUserCannotReadAdminStats(): void
    {
        $this->createAndLoginUser();
        $this->getJson('/api/admin/stats');
        self::assertResponseStatusCodeSame(403);
    }

    public function testAdminStatsReflectStoredUsersAndComics(): void
    {
        $this->createAndLoginAdmin();
        $owner = UserFactory::createOne()->object();
        ComicFactory::new()->ownedBy($owner)->create(['fileSize' => 12345]);

        $payload = $this->getJson('/api/admin/stats');

        self::assertResponseIsSuccessful();
        self::assertSame(2, $payload['stats']['totalUsers']);
        self::assertSame(2, $payload['stats']['verifiedUsers']);
        self::assertSame(1, $payload['stats']['totalComics']);
        self::assertSame(12345, $payload['stats']['storageUsed']);
    }

    public function testRegularUserCannotRunCleanupDryRun(): void
    {
        $this->createAndLoginUser();
        $this->postJson('/api/admin/cleanup/dry-run');
        self::assertResponseStatusCodeSame(403);
    }

    public function testAdminCanReadEmptyAuditLog(): void
    {
        $this->createAndLoginAdmin();
        $payload = $this->getJson('/api/admin/audit-logs');

        self::assertResponseIsSuccessful();
        self::assertSame([], $payload['logs']);
    }
}
