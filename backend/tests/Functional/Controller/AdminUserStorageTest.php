<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Entity\AdminAuditLog;
use App\Service\StorageQuotaService;
use App\Tests\Factory\ComicFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Functional\AbstractApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * What the admin screens read to answer "how much of this server is this
 * account using?".
 *
 * The list and the detail page have to agree, because an administrator
 * diagnosing a capacity problem will move between them.
 */
final class AdminUserStorageTest extends AbstractApiTestCase
{
    public function testTheUserListReportsUsageAndQuotaPerAccount(): void
    {
        $heavy = UserFactory::createOne()->object();
        ComicFactory::createOne(['owner' => $heavy, 'fileSize' => 6_000]);
        ComicFactory::createOne(['owner' => $heavy, 'fileSize' => 4_000]);
        $empty = UserFactory::createOne()->object();

        $this->createAndLoginAdmin();
        $users = array_column($this->getJson('/api/users?limit=50')['items'], null, 'id');

        self::assertSame(10_000, $users[$heavy->getId()]['storageUsedBytes']);
        self::assertSame(0, $users[$empty->getId()]['storageUsedBytes']);
        self::assertSame(0, $users[$heavy->getId()]['unmeasuredComicCount']);
        self::assertSame(
            $this->configuredQuotaBytes(),
            $users[$heavy->getId()]['storageQuotaBytes'],
            'The quota shown must be the limit actually enforced, not a placeholder.'
        );
    }

    public function testComicsWithNoRecordedSizeAreFlaggedRatherThanHidden(): void
    {
        $user = UserFactory::createOne()->object();
        ComicFactory::createOne(['owner' => $user, 'fileSize' => 2_048]);
        ComicFactory::createOne(['owner' => $user, 'fileSize' => null]);

        $this->createAndLoginAdmin();
        $users = array_column($this->getJson('/api/users?limit=50')['items'], null, 'id');

        self::assertSame(2_048, $users[$user->getId()]['storageUsedBytes']);
        self::assertSame(1, $users[$user->getId()]['unmeasuredComicCount']);
    }

    public function testTheDetailEndpointAgreesWithTheList(): void
    {
        $user = UserFactory::createOne()->object();
        ComicFactory::createOne(['owner' => $user, 'fileSize' => 1_234]);
        ComicFactory::createOne(['owner' => $user, 'fileSize' => null]);

        $this->createAndLoginAdmin();
        $listed = array_column($this->getJson('/api/users?limit=50')['items'], null, 'id')[$user->getId()];
        $detail = $this->getJson('/api/users/' . $user->getId())['user'];

        foreach (['comicCount', 'storageUsedBytes', 'storageQuotaBytes', 'unmeasuredComicCount'] as $field) {
            self::assertSame($listed[$field], $detail[$field], $field . ' disagrees between the list and the detail page.');
        }
    }

    public function testTheAdminCanSetAndClearAQuotaOverride(): void
    {
        $user = UserFactory::createOne()->object();
        $admin = $this->createAndLoginAdmin();

        $updated = $this->patchJson('/api/users/' . $user->getId() . '/storage-quota', [
            'storageQuotaOverrideBytes' => 2_147_483_648,
        ]);

        self::assertResponseIsSuccessful();
        self::assertSame(2_147_483_648, $updated['user']['storageQuotaBytes']);
        self::assertSame(2_147_483_648, $updated['user']['storageQuotaOverrideBytes']);
        self::assertSame($this->configuredQuotaBytes(), $updated['user']['storageDefaultQuotaBytes']);

        $detail = $this->getJson('/api/users/' . $user->getId())['user'];
        self::assertSame(2_147_483_648, $detail['storageQuotaBytes']);
        self::assertSame(2_147_483_648, $detail['storageQuotaOverrideBytes']);

        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $audit = $entityManager->getRepository(AdminAuditLog::class)->findOneBy([
            'action' => 'user_storage_quota_updated',
            'targetId' => $user->getId(),
        ]);
        self::assertNotNull($audit);
        self::assertSame($admin->getId(), $audit->getAdminUser()?->getId());
        self::assertSame(2_147_483_648, $audit->getPayload()['override_after_bytes']);

        $cleared = $this->patchJson('/api/users/' . $user->getId() . '/storage-quota', [
            'storageQuotaOverrideBytes' => null,
        ]);

        self::assertResponseIsSuccessful();
        self::assertNull($cleared['user']['storageQuotaOverrideBytes']);
        self::assertSame($this->configuredQuotaBytes(), $cleared['user']['storageQuotaBytes']);
    }

    public function testTheAdminCanMakeAnAccountExplicitlyUnlimited(): void
    {
        $user = UserFactory::createOne()->object();
        $this->createAndLoginAdmin();

        $updated = $this->patchJson('/api/users/' . $user->getId() . '/storage-quota', [
            'storageQuotaOverrideBytes' => 0,
        ]);

        self::assertResponseIsSuccessful();
        self::assertSame(0, $updated['user']['storageQuotaBytes']);
        self::assertSame(0, $updated['user']['storageQuotaOverrideBytes']);
        self::assertSame('Storage quota set to unlimited.', $updated['message']);
    }

    public function testAUserCannotChangeTheirOwnQuota(): void
    {
        $user = $this->createAndLoginUser();

        $this->patchJson('/api/users/' . $user->getId() . '/storage-quota', [
            'storageQuotaOverrideBytes' => 0,
        ]);

        self::assertResponseStatusCodeSame(403);
    }

    /**
     * @dataProvider invalidQuotaOverrides
     */
    public function testInvalidQuotaOverridesAreRejected(mixed $override): void
    {
        $user = UserFactory::createOne()->object();
        $this->createAndLoginAdmin();

        $payload = $this->patchJson('/api/users/' . $user->getId() . '/storage-quota', [
            'storageQuotaOverrideBytes' => $override,
        ]);

        self::assertResponseStatusCodeSame(400);
        self::assertArrayHasKey('storageQuotaOverrideBytes', $payload['errors']);
    }

    /** @return iterable<string, array{mixed}> */
    public function invalidQuotaOverrides(): iterable
    {
        yield 'negative' => [-1];
        yield 'numeric string' => ['10737418240'];
        yield 'fraction' => [1.5];
        yield 'boolean' => [false];
        yield 'larger than JavaScript can represent exactly' => [StorageQuotaService::MAX_QUOTA_BYTES + 1];
    }

    public function testStorageSurvivesSearchAndPaging(): void
    {
        $found = UserFactory::createOne(['name' => 'Storage Search Target'])->object();
        ComicFactory::createOne(['owner' => $found, 'fileSize' => 777]);

        $this->createAndLoginAdmin();
        $response = $this->getJson('/api/users?limit=10&page=1&search=Storage+Search+Target');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $response['items']);
        self::assertSame(777, $response['items'][0]['storageUsedBytes']);
    }

    public function testAMissingUserIsStillNotFound(): void
    {
        $this->createAndLoginAdmin();
        $this->getJson('/api/users/99999999');

        self::assertResponseStatusCodeSame(404);
    }

    private function configuredQuotaBytes(): int
    {
        return (int) static::getContainer()->getParameter('upload_user_quota_bytes');
    }

    /* ---------------------------------------------------------------------- */
    /* What an account is told about itself                                    */
    /* ---------------------------------------------------------------------- */

    /**
     * The account's own view has to be the administrator's view of it.
     *
     * They are read through different doors — `/api/me/storage` for the account,
     * `/api/users` for the admin list — and both go back to the same grouped
     * query for exactly this reason: an account told it has room and an
     * administrator told it has none would be a support call with no answer.
     */
    public function testAnAccountSeesTheSameFiguresAnAdministratorDoes(): void
    {
        $reader = $this->createAndLoginUser(['email' => 'own-storage@example.com']);
        ComicFactory::createOne(['owner' => $reader, 'fileSize' => 3_000]);
        ComicFactory::createOne(['owner' => $reader, 'fileSize' => 5_000]);

        $mine = $this->getJson('/api/me/storage');

        self::assertResponseIsSuccessful();
        self::assertSame(8_000, $mine['storageUsedBytes']);
        self::assertSame(2, $mine['comicCount']);
        self::assertSame(0, $mine['unmeasuredComicCount']);
        self::assertSame($this->configuredQuotaBytes(), $mine['storageQuotaBytes']);

        $this->createAndLoginAdmin();
        $listed = array_column($this->getJson('/api/users?limit=50')['items'], null, 'id')[$reader->getId()];

        foreach (['comicCount', 'storageUsedBytes', 'storageQuotaBytes', 'unmeasuredComicCount'] as $field) {
            self::assertSame($listed[$field], $mine[$field], $field . ' disagrees with the admin list.');
        }
    }

    /** A comic shared with you is the owner's file; it costs you nothing. */
    public function testComicsSharedWithAnAccountDoNotCountAgainstIt(): void
    {
        $owner = UserFactory::createOne()->object();
        ComicFactory::createOne(['owner' => $owner, 'fileSize' => 9_000]);

        $this->createAndLoginUser(['email' => 'borrower@example.com']);

        self::assertSame(0, $this->getJson('/api/me/storage')['storageUsedBytes']);
        self::assertSame(0, $this->getJson('/api/me/storage')['comicCount']);
    }

    /** Unmeasured comics are flagged for the account too, not only the admin. */
    public function testAnAccountIsToldWhenItsOwnTotalIsIncomplete(): void
    {
        $reader = $this->createAndLoginUser(['email' => 'incomplete@example.com']);
        ComicFactory::createOne(['owner' => $reader, 'fileSize' => 2_048]);
        ComicFactory::createOne(['owner' => $reader, 'fileSize' => null]);

        $mine = $this->getJson('/api/me/storage');

        self::assertSame(2_048, $mine['storageUsedBytes']);
        self::assertSame(1, $mine['unmeasuredComicCount']);
    }

    public function testStorageFiguresNeedAnAccount(): void
    {
        $this->browser()->request('GET', '/api/me/storage', [], [], ['HTTP_ACCEPT' => 'application/json']);

        self::assertResponseStatusCodeSame(401);
    }
}
