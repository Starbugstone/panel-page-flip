<?php

namespace App\Tests\Functional\Controller;

use App\Tests\Factory\UserFactory;
use App\Tests\Functional\AbstractApiTestCase;

/**
 * The admin user list draws a page of accounts at a time, so its query count has
 * to stay independent of how many are on that page.
 *
 * This exists because it very nearly did not hold. Adding the personal
 * metadata credential as an inverse-side one-to-one on User put a query behind
 * every user hydration in the application — Doctrine cannot lazy-load that side
 * of the association, so there is no proxy to defer it. Nothing on this endpoint
 * was watching; the library's own query-count test caught it by accident,
 * several layers away from the change that caused it.
 */
final class AdminUserListQueryTest extends AbstractApiTestCase
{
    /**
     * Two page sizes compared against each other rather than against a hard
     * number, so the assertion survives unrelated changes to the endpoint.
     */
    public function testTheUserListDoesNotQueryPerUser(): void
    {
        $this->createAndLoginAdmin();

        UserFactory::createMany(2);
        $this->getJson('/api/users?limit=50');
        self::assertResponseIsSuccessful();
        $fewUsersQueries = $this->executedQueryCount();

        UserFactory::createMany(6);
        $this->getJson('/api/users?limit=50');
        self::assertResponseIsSuccessful();
        $manyUsersQueries = $this->executedQueryCount();

        self::assertSame(
            $fewUsersQueries,
            $manyUsersQueries,
            'Listing users runs one query per account; a per-user lookup has been un-batched. '
            .'An inverse-side one-to-one on User is the usual cause — it cannot be lazy-loaded.'
        );
    }

    /** Whether a user brought their own token, never which one. */
    public function testTheListStillReportsPersonalCredentialsCorrectly(): void
    {
        $withToken = UserFactory::createOne()->object();
        $this->loginAs($withToken);
        $this->putJson('/api/me/metadata-credentials', ['metronToken' => 'personal-metron-token']);

        $without = UserFactory::createOne()->object();
        $this->createAndLoginAdmin();

        $users = array_column($this->getJson('/api/users?limit=50')['items'], null, 'id');

        self::assertTrue($users[$withToken->getId()]['hasPersonalMetadataCredential']);
        self::assertFalse($users[$without->getId()]['hasPersonalMetadataCredential']);
    }

    private function executedQueryCount(): int
    {
        $holder = self::getContainer()->get('doctrine.debug_data_holder');

        return array_sum(array_map('count', $holder->getData()));
    }
}
