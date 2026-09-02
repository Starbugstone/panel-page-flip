<?php

namespace App\Tests\Functional\Controller;

use App\Entity\AdminAuditLog;
use App\Service\Pagination\PaginationRequest;
use App\Tests\Factory\ComicFactory;
use App\Tests\Factory\TagFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Functional\AbstractApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Server-side paging, search and filtering for the admin tables.
 *
 * These lists used to be fetched whole and filtered in the browser, so the
 * things worth pinning down are that a page really is a page, that the totals
 * describe the whole filtered set rather than the page, and that the new
 * owner/creator filters cannot be used by a non-admin to read someone else's
 * library.
 */
final class AdminPaginationTest extends AbstractApiTestCase
{
    /**
     * Accounts a search test needs to exist without matching anything it looks
     * for.
     *
     * Named rather than left to the factory, because the factory's names come
     * from Faker's real-surname list — which contains Gordon, among roughly one
     * name in two thousand. A random filler that happened to be "… Gordon" made
     * the searches below fail about once in five hundred runs, which reads as a
     * flaky suite rather than as the collision it is. Anything a search test
     * counts on *not* matching has to say so here.
     */
    private static function createNonMatchingUsers(int $count): void
    {
        UserFactory::createSequence(array_map(static fn (int $n): array => [
            'name' => sprintf('Unmatched Filler %d', $n),
            'email' => sprintf('filler%d@example.com', $n),
        ], range(1, $count)));
    }

    /** An administrator whose own name and email cannot match a search either. */
    private function createAndLoginSearchAdmin(): void
    {
        $this->createAndLoginAdmin(['name' => 'Unmatched Admin', 'email' => 'search-admin@example.com']);
    }

    public function testUserListReturnsOnePageAndTheTotalForTheWholeSet(): void
    {
        $this->createAndLoginAdmin();
        UserFactory::createMany(7);

        $payload = $this->getJson('/api/users?page=1&limit=3');

        self::assertResponseIsSuccessful();
        self::assertCount(3, $payload['items']);
        // Seven created plus the admin doing the asking.
        self::assertSame(8, $payload['pagination']['totalItems']);
        self::assertSame(3, $payload['pagination']['totalPages']);
        self::assertSame(1, $payload['pagination']['page']);
    }

    public function testUserListPagesDoNotOverlap(): void
    {
        $this->createAndLoginAdmin();
        UserFactory::createMany(5);

        $firstIds = array_column($this->getJson('/api/users?page=1&limit=3')['items'], 'id');
        $secondIds = array_column($this->getJson('/api/users?page=2&limit=3')['items'], 'id');

        self::assertCount(3, $firstIds);
        self::assertCount(3, $secondIds);
        self::assertSame([], array_intersect($firstIds, $secondIds));
    }

    public function testUserListClampsAnOversizedPageSize(): void
    {
        $this->createAndLoginAdmin();

        $payload = $this->getJson('/api/users?limit=100000');

        self::assertResponseIsSuccessful();
        self::assertSame(PaginationRequest::MAX_LIMIT, $payload['pagination']['limit']);
    }

    public function testUserListTreatsAnInvalidPageAsTheFirst(): void
    {
        $this->createAndLoginAdmin();

        $payload = $this->getJson('/api/users?page=-4&limit=nonsense');

        self::assertResponseIsSuccessful();
        self::assertSame(1, $payload['pagination']['page']);
        self::assertGreaterThanOrEqual(1, $payload['pagination']['limit']);
    }

    public function testUserListPastTheLastPageIsEmptyButStillReportsTheTotal(): void
    {
        $this->createAndLoginAdmin();
        UserFactory::createMany(2);

        $payload = $this->getJson('/api/users?page=50&limit=10');

        self::assertResponseIsSuccessful();
        self::assertSame([], $payload['items']);
        self::assertSame(3, $payload['pagination']['totalItems']);
    }

    public function testUserSearchCountsOnlyMatchingUsers(): void
    {
        $this->createAndLoginSearchAdmin();
        UserFactory::createOne(['name' => 'Barbara Gordon', 'email' => 'oracle@example.com']);
        UserFactory::createOne(['name' => 'Bruce Wayne', 'email' => 'bat@example.com']);
        self::createNonMatchingUsers(4);

        $payload = $this->getJson('/api/users?search=' . urlencode('gordon'));

        self::assertResponseIsSuccessful();
        self::assertSame(1, $payload['pagination']['totalItems']);
        self::assertSame('Barbara Gordon', $payload['items'][0]['name']);
    }

    public function testUserSearchMatchesTheEmailAddressToo(): void
    {
        $this->createAndLoginSearchAdmin();
        UserFactory::createOne(['name' => 'Barbara Gordon', 'email' => 'oracle@example.com']);

        $payload = $this->getJson('/api/users?search=oracle');

        self::assertSame(1, $payload['pagination']['totalItems']);
    }

    public function testUserSearchAndVerifiedFilterApplyTogether(): void
    {
        $this->createAndLoginSearchAdmin();
        UserFactory::new()->unverified()->create(['name' => 'Gordon Pending', 'email' => 'pending@example.com']);
        UserFactory::createOne(['name' => 'Gordon Verified', 'email' => 'verified@example.com']);

        $payload = $this->getJson('/api/users?verified=false&search=gordon');

        // The name/email alternatives must be grouped: an ungrouped OR would
        // let every unverified-or-name-matching user through.
        self::assertResponseIsSuccessful();
        self::assertSame(1, $payload['pagination']['totalItems']);
        self::assertSame('Gordon Pending', $payload['items'][0]['name']);
    }

    public function testUserSearchTreatsWildcardCharactersLiterally(): void
    {
        $this->createAndLoginAdmin();
        UserFactory::createOne(['name' => 'Fifty% Off', 'email' => 'discount@example.com']);
        UserFactory::createMany(3);

        // A bare "%" is the decisive case: unescaped it is a wildcard matching
        // every row, so this only passes if it is treated as a literal.
        $payload = $this->getJson('/api/users?search=' . urlencode('%'));

        self::assertResponseIsSuccessful();
        self::assertSame(1, $payload['pagination']['totalItems']);
        self::assertSame('Fifty% Off', $payload['items'][0]['name']);
    }

    public function testUserListReportsOwnedComicAndTagTotals(): void
    {
        $admin = $this->createAndLoginAdmin();
        ComicFactory::new()->ownedBy($admin)->many(2)->create();
        TagFactory::new()->createdBy($admin)->create();

        $payload = $this->getJson('/api/users');

        $row = current(array_filter($payload['items'], static fn (array $u): bool => $u['id'] === $admin->getId()));
        self::assertSame(2, $row['comicCount']);
        self::assertSame(1, $row['tagCount']);
    }

    public function testUserColumnFiltersAndComicCountSortApplyToTheWholeResultSet(): void
    {
        $this->createAndLoginSearchAdmin();
        $barbara = UserFactory::createOne(['name' => 'Barbara Gordon', 'email' => 'oracle@example.com']);
        $bruce = UserFactory::createOne(['name' => 'Bruce Wayne', 'email' => 'bat@example.com']);
        ComicFactory::new()->ownedBy($barbara)->with(['fileSize' => 524_288])->many(2)->create();
        ComicFactory::new()->ownedBy($bruce)->create(['fileSize' => 262_144]);

        $filtered = $this->getJson('/api/users?filterIdentity=oracle&filterComicCount=2');
        self::assertResponseIsSuccessful();
        self::assertSame([$barbara->getId()], array_column($filtered['items'], 'id'));
        self::assertSame(2, $filtered['comicCountMax']);

        $comicRange = $this->getJson('/api/users?filterComicCount=' . urlencode('1..2'));
        self::assertResponseIsSuccessful();
        self::assertEqualsCanonicalizing(
            [$barbara->getId(), $bruce->getId()],
            array_column($comicRange['items'], 'id'),
        );

        $byStorage = $this->getJson('/api/users?filterStorage=' . urlencode('1.0 MiB') . '&sort=storage&direction=DESC');
        self::assertResponseIsSuccessful();
        self::assertSame([$barbara->getId()], array_column($byStorage['items'], 'id'));

        $storageRange = $this->getJson('/api/users?filterStorage=' . urlencode('500000..1100000'));
        self::assertResponseIsSuccessful();
        self::assertSame([$barbara->getId()], array_column($storageRange['items'], 'id'));
        self::assertSame(1_048_576, $storageRange['storageMaxBytes']);

        $sorted = $this->getJson('/api/users?sort=comicCount&direction=DESC');
        self::assertResponseIsSuccessful();
        self::assertSame($barbara->getId(), $sorted['items'][0]['id']);
    }

    public function testRoleFilteringAndSortingFollowTheBadgesTheTableShows(): void
    {
        $basic = UserFactory::createOne(['name' => 'Basic Account', 'roles' => ['ROLE_USER']]);
        $editor = UserFactory::createOne(['name' => 'Editor Account', 'roles' => ['ROLE_EDITOR', 'ROLE_USER']]);
        $admin = $this->createAndLoginAdmin(['name' => 'Admin Account']);

        $users = $this->getJson('/api/users?filterRole=User');
        self::assertResponseIsSuccessful();
        self::assertSame([$basic->getId()], array_column($users['items'], 'id'));

        // Both Editor and User contain \"e\". A substring filter must retain
        // both labels rather than whichever one the label map lists first.
        $partial = $this->getJson('/api/users?filterRole=e');
        self::assertResponseIsSuccessful();
        self::assertEqualsCanonicalizing(
            [$basic->getId(), $editor->getId()],
            array_column($partial['items'], 'id'),
        );

        $sorted = $this->getJson('/api/users?sort=role&direction=ASC');
        self::assertResponseIsSuccessful();
        self::assertSame(
            [$admin->getId(), $editor->getId(), $basic->getId()],
            array_column($sorted['items'], 'id'),
        );
    }

    public function testStorageFilterCannotCrossTheUnitBoundaryShownInTheCell(): void
    {
        $this->createAndLoginAdmin();
        $justBelowOneMib = UserFactory::createOne(['name' => 'Near Boundary']);
        $exactlyOneMib = UserFactory::createOne(['name' => 'At Boundary']);
        ComicFactory::new()->ownedBy($justBelowOneMib)->create(['fileSize' => 1024 ** 2 - 1]);
        ComicFactory::new()->ownedBy($exactlyOneMib)->create(['fileSize' => 1024 ** 2]);

        $near = $this->getJson('/api/users?filterStorage=' . urlencode('1024.0 KiB'));
        self::assertResponseIsSuccessful();
        self::assertSame([$justBelowOneMib->getId()], array_column($near['items'], 'id'));

        // No row can display this: values below one MiB use KiB, and the first
        // value in the MiB tier displays as 1.0 MiB.
        $impossible = $this->getJson('/api/users?filterStorage=' . urlencode('0.9 MiB'));
        self::assertResponseIsSuccessful();
        self::assertSame([], $impossible['items']);
    }

    public function testDateColumnFiltersAcceptInclusiveAndOpenRanges(): void
    {
        $before = UserFactory::createOne([
            'name' => 'Before Range',
            'createdAt' => new \DateTimeImmutable('2026-07-31 23:59:59'),
        ]);
        $inside = UserFactory::createOne([
            'name' => 'Inside Range',
            'createdAt' => new \DateTimeImmutable('2026-08-31 23:59:59'),
        ]);
        $after = UserFactory::createOne([
            'name' => 'After Range',
            'createdAt' => new \DateTimeImmutable('2026-09-01 00:00:00'),
        ]);
        $this->createAndLoginAdmin();

        $closed = $this->getJson('/api/users?filterCreatedAt=2026-08-01..2026-08-31');
        self::assertResponseIsSuccessful();
        self::assertSame([$inside->getId()], array_column($closed['items'], 'id'));

        $open = $this->getJson('/api/users?filterCreatedAt=..2026-08-31');
        self::assertResponseIsSuccessful();
        self::assertEqualsCanonicalizing(
            [$before->getId(), $inside->getId()],
            array_column($open['items'], 'id'),
        );
        self::assertNotContains($after->getId(), array_column($open['items'], 'id'));
    }

    public function testDateColumnFiltersFollowTheCalendarDayDisplayedInTheBrowser(): void
    {
        $inside = UserFactory::createOne([
            'name' => 'After local midnight',
            // 00:30 on 29 March in Paris.
            'createdAt' => new \DateTimeImmutable('2026-03-28 23:30:00 UTC'),
        ]);
        $outside = UserFactory::createOne([
            'name' => 'After the next local midnight',
            // 00:30 on 30 March after the daylight-saving change.
            'createdAt' => new \DateTimeImmutable('2026-03-29 22:30:00 UTC'),
        ]);
        $this->createAndLoginAdmin();

        $payload = $this->getJson('/api/users?filterCreatedAt=2026-03-29&filterTimezone=Europe%2FParis');

        self::assertResponseIsSuccessful();
        self::assertSame([$inside->getId()], array_column($payload['items'], 'id'));
        self::assertNotContains($outside->getId(), array_column($payload['items'], 'id'));
    }

    /**
     * A word the Verified? column never shows excludes every row. Dropping the
     * filter instead would answer a search for "nobody" with the whole user
     * table, which reads as though every account had matched it.
     */
    public function testAColumnFilterMatchingNoLabelReturnsNothing(): void
    {
        $this->createAndLoginAdmin();
        UserFactory::createOne(['email' => 'someone@example.com']);

        $payload = $this->getJson('/api/users?filterVerified=nobody');

        self::assertResponseIsSuccessful();
        self::assertSame([], $payload['items']);
        self::assertSame(0, $payload['pagination']['totalItems']);
    }

    public function testUnknownSortFieldFallsBackToTheDefaultInsteadOfFailing(): void
    {
        $this->createAndLoginAdmin();
        UserFactory::createMany(2);

        $payload = $this->getJson('/api/users?sort=password&direction=ASC');

        self::assertResponseIsSuccessful();
        self::assertCount(3, $payload['items']);
    }

    public function testUserListRejectsANonAdmin(): void
    {
        $this->createAndLoginUser();
        UserFactory::createMany(3);

        $this->getJson('/api/users?page=1&limit=100');

        self::assertResponseStatusCodeSame(403);
    }

    public function testUserDetailReturns404ForAMissingUser(): void
    {
        $this->createAndLoginAdmin();

        $this->getJson('/api/users/99999999');

        self::assertResponseStatusCodeSame(404);
    }

    public function testUserDetailIncludesTheDropboxSummaryForAnAdmin(): void
    {
        $this->createAndLoginAdmin();
        $target = UserFactory::createOne();

        $payload = $this->getJson('/api/users/' . $target->getId());

        self::assertResponseIsSuccessful();
        self::assertFalse($payload['user']['dropboxConnected']);
        self::assertArrayHasKey('comicCount', $payload['user']);
    }

    public function testUserDetailReportsARefreshOnlyDropboxConnection(): void
    {
        $this->createAndLoginAdmin();
        $target = UserFactory::createOne();
        $target->setDropboxRefreshToken('stored-refresh-token');
        self::getContainer()->get('doctrine')->getManager()->flush();

        $payload = $this->getJson('/api/users/' . $target->getId());

        self::assertResponseIsSuccessful();
        self::assertTrue($payload['user']['dropboxConnected']);
    }

    public function testAdminComicListIsPagedAndFilteredByOwner(): void
    {
        $admin = $this->createAndLoginAdmin();
        $owner = UserFactory::createOne();
        ComicFactory::new()->ownedBy($owner)->many(4)->create();
        ComicFactory::new()->ownedBy($admin)->many(3)->create();

        $payload = $this->getJson('/api/comics?adminContext=true&ownerId=' . $owner->getId() . '&limit=2');

        self::assertResponseIsSuccessful();
        self::assertCount(2, $payload['items']);
        self::assertSame(4, $payload['pagination']['totalItems']);
        foreach ($payload['items'] as $comic) {
            self::assertSame($owner->getId(), $comic['owner']['id']);
        }
    }

    public function testOwnerIdIsIgnoredForANonAdmin(): void
    {
        $caller = $this->createAndLoginUser();
        $other = UserFactory::createOne();
        ComicFactory::new()->ownedBy($other)->many(3)->create();
        ComicFactory::new()->ownedBy($caller)->create();

        $payload = $this->getJson('/api/comics?adminContext=true&ownerId=' . $other->getId());

        self::assertResponseIsSuccessful();
        // adminContext and ownerId are both inert without ROLE_ADMIN: the caller
        // still sees exactly their own library.
        self::assertCount(1, $payload['comics']);
    }

    public function testAdminComicSearchMatchesTheOwnerAndCountsOnce(): void
    {
        $this->createAndLoginAdmin();
        $owner = UserFactory::createOne(['name' => 'Selina Kyle', 'email' => 'cat@example.com']);
        $comic = ComicFactory::new()->ownedBy($owner)->create();
        // Two matching tags on one comic must not count that comic twice.
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $managed = $entityManager->find(\App\Entity\Comic::class, $comic->getId());
        $managed->addTag(TagFactory::new()->createdBy($owner)->create(['name' => 'Selina A']));
        $managed->addTag(TagFactory::new()->createdBy($owner)->create(['name' => 'Selina B']));
        $entityManager->flush();

        $payload = $this->getJson('/api/comics?adminContext=true&search=selina');

        self::assertResponseIsSuccessful();
        self::assertSame(1, $payload['pagination']['totalItems']);
        self::assertCount(1, $payload['items']);
    }

    public function testAdminComicColumnFiltersCanBeCombined(): void
    {
        $this->createAndLoginAdmin();
        $owner = UserFactory::createOne(['name' => 'Selina Kyle', 'email' => 'cat@example.com']);
        $comic = ComicFactory::new()->ownedBy($owner)->create(['title' => 'Gotham Nights', 'pageCount' => 42]);
        $tag = TagFactory::new()->createdBy($owner)->create(['name' => 'Noir']);
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $entityManager->find(\App\Entity\Comic::class, $comic->getId())
            ->addTag($entityManager->find(\App\Entity\Tag::class, $tag->getId()));
        $entityManager->flush();

        $payload = $this->getJson('/api/comics?adminContext=true&filterTitleAuthor=gotham&filterOwner=selina&filterTags=noir&filterPageCount=' . urlencode('40..45') . '&sort=tags&direction=ASC');

        self::assertResponseIsSuccessful();
        self::assertSame([$comic->getId()], array_column($payload['items'], 'id'));
        self::assertGreaterThanOrEqual(42, $payload['pageCountMax']);

        $byDisplayedOwner = $this->getJson('/api/comics?adminContext=true&sort=owner&direction=ASC');
        self::assertResponseIsSuccessful();
        self::assertContains($comic->getId(), array_column($byDisplayedOwner['items'], 'id'));
    }

    public function testAdminTagListIsPagedAndFilteredByCreator(): void
    {
        $this->createAndLoginAdmin();
        $creator = UserFactory::createOne();
        TagFactory::new()->createdBy($creator)->many(3)->create();
        TagFactory::createMany(2);
        TagFactory::createOne(['name' => 'Everyone', 'isGlobal' => true, 'creator' => null]);

        $payload = $this->getJson('/api/tags?all=true&adminContext=true&creatorId=' . $creator->getId());

        self::assertResponseIsSuccessful();
        self::assertSame(3, $payload['pagination']['totalItems']);
        foreach ($payload['items'] as $tag) {
            self::assertSame($creator->getId(), $tag['creator']['id']);
            self::assertFalse($tag['isGlobal']);
        }
    }

    public function testAdminTagListDistinguishesGlobalTagsAndReportsUsage(): void
    {
        $admin = $this->createAndLoginAdmin();
        $tag = TagFactory::createOne(['name' => 'Everyone', 'isGlobal' => true, 'creator' => null]);
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        foreach (ComicFactory::new()->ownedBy($admin)->many(2)->create() as $comic) {
            $entityManager->find(\App\Entity\Comic::class, $comic->getId())
                ->addTag($entityManager->find(\App\Entity\Tag::class, $tag->getId()));
        }
        $entityManager->flush();

        $payload = $this->getJson('/api/tags?all=true&adminContext=true&search=everyone');

        self::assertResponseIsSuccessful();
        self::assertSame(1, $payload['pagination']['totalItems']);
        self::assertTrue($payload['items'][0]['isGlobal']);
        self::assertNull($payload['items'][0]['creator']);
        self::assertSame(2, $payload['items'][0]['comicCount']);
    }

    public function testAdminTagColumnFiltersAndUsageSortWorkTogether(): void
    {
        $admin = $this->createAndLoginAdmin();
        $popular = TagFactory::createOne(['name' => 'Popular Noir', 'isGlobal' => true, 'creator' => null]);
        $unused = TagFactory::createOne(['name' => 'Unused Noir', 'isGlobal' => true, 'creator' => null]);
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        foreach (ComicFactory::new()->ownedBy($admin)->many(2)->create() as $comic) {
            $entityManager->find(\App\Entity\Comic::class, $comic->getId())
                ->addTag($entityManager->find(\App\Entity\Tag::class, $popular->getId()));
        }
        $entityManager->flush();

        $payload = $this->getJson('/api/tags?all=true&adminContext=true&filterName=noir&filterScope=global&sort=comicCount&direction=DESC');

        self::assertResponseIsSuccessful();
        self::assertSame([$popular->getId(), $unused->getId()], array_column($payload['items'], 'id'));
        self::assertSame(2, $payload['comicCountMax']);

        $range = $this->getJson('/api/tags?all=true&adminContext=true&filterComicCount=' . urlencode('1..2'));
        self::assertResponseIsSuccessful();
        self::assertSame([$popular->getId()], array_column($range['items'], 'id'));
    }

    public function testTagFixedLabelsFilterAndSortAsTheyAreDisplayed(): void
    {
        $creator = UserFactory::createOne(['name' => 'Personal Creator']);
        $personal = TagFactory::new()->createdBy($creator)->create([
            'name' => 'Personal Label',
            'hideFromLibrary' => false,
        ]);
        $global = TagFactory::createOne([
            'name' => 'Global Label',
            'isGlobal' => true,
            'creator' => null,
            'hideFromLibrary' => true,
        ]);
        $this->createAndLoginAdmin();

        // \"l\" occurs in both Global and Personal.
        $scope = $this->getJson('/api/tags?all=true&adminContext=true&filterScope=l&sort=isGlobal&direction=ASC');
        self::assertResponseIsSuccessful();
        self::assertSame([$global->getId(), $personal->getId()], array_column($scope['items'], 'id'));

        // The cell says System for a null creator, so a partial label must find
        // it just as a partial person name finds an ordinary creator.
        $system = $this->getJson('/api/tags?all=true&adminContext=true&filterCreator=sys&sort=creator&direction=ASC');
        self::assertResponseIsSuccessful();
        self::assertSame([$global->getId()], array_column($system['items'], 'id'));

        $visibility = $this->getJson('/api/tags?all=true&adminContext=true&sort=hideFromLibrary&direction=ASC');
        self::assertResponseIsSuccessful();
        self::assertSame([$global->getId(), $personal->getId()], array_column($visibility['items'], 'id'));
    }

    public function testATagScopeFilterMatchingNoLabelReturnsNothing(): void
    {
        $this->createAndLoginAdmin();
        TagFactory::createOne(['name' => 'Noir', 'isGlobal' => true, 'creator' => null]);

        $payload = $this->getJson('/api/tags?all=true&adminContext=true&filterScope=nonsense');

        self::assertResponseIsSuccessful();
        self::assertSame([], $payload['items']);
        self::assertSame(0, $payload['pagination']['totalItems']);
    }

    public function testCreatorIdDoesNotLeakAnotherUsersTagsToANonAdmin(): void
    {
        $this->createAndLoginUser();
        $other = UserFactory::createOne();
        TagFactory::new()->createdBy($other)->create(['name' => 'Private Tag']);

        $payload = $this->getJson('/api/tags?all=true&adminContext=true&creatorId=' . $other->getId());

        self::assertResponseIsSuccessful();
        self::assertSame([], array_filter(
            $payload['tags'],
            static fn (array $tag): bool => $tag['name'] === 'Private Tag'
        ));
    }

    public function testAuditLogIsPagedAndFilterableByAction(): void
    {
        $admin = $this->createAndLoginAdmin();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        foreach (['user_update', 'user_update', 'user_delete'] as $action) {
            $log = (new AdminAuditLog())
                ->setAdminUser($entityManager->find(\App\Entity\User::class, $admin->getId()))
                ->setAction($action)
                ->setTargetType('user')
                ->setTargetId(42);
            $entityManager->persist($log);
        }
        $entityManager->flush();

        $payload = $this->getJson('/api/admin/audit-logs?action=user_update&limit=1');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $payload['items']);
        self::assertSame(2, $payload['pagination']['totalItems']);
        self::assertContains('user_delete', $payload['filters']['actions']);
    }

    public function testAuditLogSearchMatchesATargetId(): void
    {
        $admin = $this->createAndLoginAdmin();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        foreach ([42, 43] as $targetId) {
            $entityManager->persist((new AdminAuditLog())
                ->setAdminUser($entityManager->find(\App\Entity\User::class, $admin->getId()))
                ->setAction('user_update')
                ->setTargetType('user')
                ->setTargetId($targetId));
        }
        $entityManager->flush();

        // Target ids are integers and DQL has no portable CAST, so a numeric
        // term is matched exactly rather than as a substring.
        $payload = $this->getJson('/api/admin/audit-logs?search=42');

        self::assertResponseIsSuccessful();
        self::assertSame(1, $payload['pagination']['totalItems']);
        self::assertSame(42, $payload['items'][0]['targetId']);
    }

    public function testAuditLogColumnFiltersMatchAdminTargetAndDetails(): void
    {
        $admin = $this->createAndLoginAdmin(['name' => 'Audit Operator', 'email' => 'operator@example.com']);
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist((new AdminAuditLog())
            ->setAdminUser($entityManager->find(\App\Entity\User::class, $admin->getId()))
            ->setAction('comic_update')
            ->setTargetType('comic')
            ->setTargetId(77)
            ->setPayload(['reason' => 'duplicate']));
        $entityManager->flush();

        $payload = $this->getJson('/api/admin/audit-logs?filterAdmin=operator&filterAction=update&filterTarget=77&filterDetails=duplicate&sort=admin&direction=ASC');

        self::assertResponseIsSuccessful();
        self::assertSame(1, $payload['pagination']['totalItems']);
        self::assertSame(77, $payload['items'][0]['targetId']);
    }

    public function testAuditLogRejectsANonAdmin(): void
    {
        $this->createAndLoginUser();

        $this->getJson('/api/admin/audit-logs');

        self::assertResponseStatusCodeSame(403);
    }
}
