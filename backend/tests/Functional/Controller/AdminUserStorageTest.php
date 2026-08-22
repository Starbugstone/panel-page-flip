<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Tests\Factory\ComicFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Functional\AbstractApiTestCase;

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
