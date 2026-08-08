<?php

namespace App\Tests\Functional\Controller;

use App\Entity\Tag;
use App\Repository\TagRepository;
use App\Tests\Factory\TagFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Functional\AbstractApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The tag autocomplete: what it may return, and how much of it.
 *
 * The scope and the limit are the query's job. They used to be neither — every
 * matching tag in the install was hydrated and then filtered down to the
 * caller's own in PHP — so a single keystroke was proportional to the size of
 * the whole tag table.
 */
final class TagSearchScopeTest extends AbstractApiTestCase
{
    public function testSearchReturnsOnlyGlobalTagsAndTheCallersOwn(): void
    {
        $stranger = UserFactory::createOne()->object();
        $caller = UserFactory::createOne()->object();

        TagFactory::createOne(['name' => 'shared-heroes', 'creator' => null, 'isGlobal' => true]);
        TagFactory::createOne(['name' => 'shared-mine', 'creator' => $caller]);
        TagFactory::createOne(['name' => 'shared-theirs', 'creator' => $stranger]);

        $this->loginAs($caller);
        $payload = $this->getJson('/api/tags/search?q=shared-');

        $names = array_column($payload['tags'], 'name');
        sort($names);
        self::assertSame(['shared-heroes', 'shared-mine'], $names);
    }

    public function testAdminContextSearchStillSeesEveryTag(): void
    {
        $stranger = UserFactory::createOne()->object();
        TagFactory::createOne(['name' => 'admin-scope-theirs', 'creator' => $stranger]);

        $this->createAndLoginAdmin();
        $payload = $this->getJson('/api/tags/search?q=admin-scope-&adminContext=true');

        self::assertSame(['admin-scope-theirs'], array_column($payload['tags'], 'name'));
    }

    /**
     * A non-admin asking for the admin scope is still answered within their own,
     * so the query parameter cannot widen what anybody sees.
     */
    public function testAdminContextIsIgnoredForNonAdmins(): void
    {
        $stranger = UserFactory::createOne()->object();
        TagFactory::createOne(['name' => 'escalate-theirs', 'creator' => $stranger]);

        $this->loginAs(UserFactory::createOne()->object());
        $payload = $this->getJson('/api/tags/search?q=escalate-&adminContext=true');

        self::assertSame([], $payload['tags']);
    }

    public function testSearchIsBounded(): void
    {
        $caller = UserFactory::createOne()->object();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);

        for ($i = 0; $i < TagRepository::SEARCH_LIMIT + 10; $i++) {
            TagFactory::createOne(['name' => sprintf('bounded-%03d', $i), 'creator' => $caller]);
        }
        $entityManager->flush();

        $this->loginAs($caller);
        $payload = $this->getJson('/api/tags/search?q=bounded-');

        self::assertCount(TagRepository::SEARCH_LIMIT, $payload['tags']);
    }
}
