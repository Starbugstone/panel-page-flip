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

    public function testAuditLogRejectsANonAdmin(): void
    {
        $this->createAndLoginUser();

        $this->getJson('/api/admin/audit-logs');

        self::assertResponseStatusCodeSame(403);
    }
}
