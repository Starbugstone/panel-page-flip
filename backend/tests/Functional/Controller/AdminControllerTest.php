<?php

namespace App\Tests\Functional\Controller;

use App\Service\ComicFormatService;
use App\Tests\Factory\ComicFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Functional\AbstractApiTestCase;

class AdminControllerTest extends AbstractApiTestCase
{
    public function testEmptyComicFormatUpdateAlwaysKeepsCbzEnabled(): void
    {
        $this->createAndLoginAdmin();

        $payload = $this->putJson('/api/admin/comic-formats', ['enabled' => []]);

        self::assertResponseIsSuccessful();
        self::assertTrue($payload['formats']['cbz']['enabled']);
    }

    public function testCbzFreeComicFormatUpdateAddsCbzToTheSavedConfiguration(): void
    {
        $this->createAndLoginAdmin();
        $status = self::getContainer()->get(ComicFormatService::class)->status(true);
        $optional = array_key_first(array_filter(
            $status,
            static fn (array $value, string $name): bool => $name !== 'cbz' && $value['available'],
            ARRAY_FILTER_USE_BOTH
        ));
        if ($optional === null) self::markTestSkipped('No optional comic runtime is installed.');

        $payload = $this->putJson('/api/admin/comic-formats', ['enabled' => [$optional]]);

        self::assertResponseIsSuccessful();
        self::assertTrue($payload['formats']['cbz']['enabled']);
        self::assertTrue($payload['formats'][$optional]['enabled']);
    }

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
