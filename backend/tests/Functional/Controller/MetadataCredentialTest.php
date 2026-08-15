<?php

namespace App\Tests\Functional\Controller;

use App\Entity\User;
use App\Service\AppDataEncryptionService;
use App\Tests\Factory\UserFactory;
use App\Tests\Functional\AbstractApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * A user's own provider tokens.
 *
 * Write-only after saving, encrypted at rest, removable, and gone with the
 * account. That set of properties is what makes offering the field defensible
 * at all, so each one has a test.
 */
final class MetadataCredentialTest extends AbstractApiTestCase
{
    public function testSavingATokenReportsItAsConfiguredWithoutReturningIt(): void
    {
        $this->loginAs(UserFactory::createOne()->object());

        $this->putJson('/api/me/metadata-credentials', ['metronToken' => 'personal-metron-token']);

        self::assertResponseIsSuccessful();
        $body = (string) $this->browser()->getResponse()->getContent();
        self::assertStringNotContainsString('personal-metron-token', $body);

        $response = json_decode($body, true);
        self::assertTrue($response['configured']['metron']);
        self::assertFalse($response['configured']['comicvine']);
        self::assertNotNull($response['updatedAt']);
    }

    public function testKeepsThePersonalTokenEncryptedAtRest(): void
    {
        $this->loginAs(UserFactory::createOne()->object());
        $this->putJson('/api/me/metadata-credentials', ['metronToken' => 'personal-metron-token']);

        $stored = self::getContainer()->get(EntityManagerInterface::class)
            ->getConnection()
            ->fetchOne('SELECT metron_token FROM user_metadata_credential');

        $encryption = self::getContainer()->get(AppDataEncryptionService::class);
        self::assertIsString($stored);
        self::assertNotSame('personal-metron-token', $stored);
        self::assertTrue($encryption->isEncrypted($stored));
        self::assertSame('personal-metron-token', $encryption->decrypt($stored));
    }

    /** There is no read endpoint, and the show route is not one. */
    public function testThereIsNoWayToReadATokenBackOut(): void
    {
        $this->loginAs(UserFactory::createOne()->object());
        $this->putJson('/api/me/metadata-credentials', ['metronToken' => 'personal-metron-token']);

        $this->getJson('/api/me/metadata-credentials');

        self::assertStringNotContainsString(
            'personal-metron-token',
            (string) $this->browser()->getResponse()->getContent()
        );
    }

    public function testReplacingOneTokenLeavesTheOtherAlone(): void
    {
        $this->loginAs(UserFactory::createOne()->object());

        $this->putJson('/api/me/metadata-credentials', ['metronToken' => 'personal-metron-token']);
        $response = $this->putJson('/api/me/metadata-credentials', ['comicVineApiKey' => 'personal-comicvine-key']);

        self::assertTrue($response['configured']['metron']);
        self::assertTrue($response['configured']['comicvine']);
    }

    public function testAnExplicitNullRemovesOneToken(): void
    {
        $this->loginAs(UserFactory::createOne()->object());

        $this->putJson('/api/me/metadata-credentials', [
            'metronToken' => 'personal-metron-token',
            'comicVineApiKey' => 'personal-comicvine-key',
        ]);
        $response = $this->putJson('/api/me/metadata-credentials', ['metronToken' => null]);

        self::assertFalse($response['configured']['metron']);
        self::assertTrue($response['configured']['comicvine']);
    }

    /** A row holding no secrets is only a row. */
    public function testRemovingTheLastTokenRemovesTheRecord(): void
    {
        $this->loginAs(UserFactory::createOne()->object());
        $this->putJson('/api/me/metadata-credentials', ['metronToken' => 'personal-metron-token']);

        $response = $this->deleteJson('/api/me/metadata-credentials');

        self::assertFalse($response['configured']['metron']);
        self::assertSame(
            0,
            (int) self::getContainer()->get(EntityManagerInterface::class)
                ->getConnection()
                ->fetchOne('SELECT COUNT(*) FROM user_metadata_credential')
        );
    }

    /** Deleted with the account, which is the promise that justifies storing it. */
    public function testTheCredentialGoesWithTheAccount(): void
    {
        $user = UserFactory::createOne()->object();
        $this->loginAs($user);
        $this->putJson('/api/me/metadata-credentials', ['metronToken' => 'personal-metron-token']);

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->remove($entityManager->find(User::class, $user->getId()));
        $entityManager->flush();

        self::assertSame(
            0,
            (int) $entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM user_metadata_credential')
        );
    }

    public function testOneUserCannotSeeAnotherUsersCredentialState(): void
    {
        $owner = UserFactory::createOne()->object();
        $this->loginAs($owner);
        $this->putJson('/api/me/metadata-credentials', ['metronToken' => 'personal-metron-token']);

        $this->loginAs(UserFactory::createOne()->object());
        $response = $this->getJson('/api/me/metadata-credentials');

        self::assertFalse($response['configured']['metron']);
    }

    public function testAnonymousIsRefused(): void
    {
        $this->getJson('/api/me/metadata-credentials');

        self::assertResponseStatusCodeSame(401);
    }

    public function testATokenLongerThanAnyProviderIssuesIsRejected(): void
    {
        $this->loginAs(UserFactory::createOne()->object());

        $this->putJson('/api/me/metadata-credentials', ['metronToken' => str_repeat('a', 1_000)]);

        self::assertResponseStatusCodeSame(400);
    }

    /**
     * The limit is in bytes, because the column holds ciphertext and a
     * multibyte value passes a character count while still overflowing. Caught
     * here rather than as a database error at flush time.
     */
    public function testAMultibyteTokenThatWouldOverflowTheColumnIsRejected(): void
    {
        $this->loginAs(UserFactory::createOne()->object());

        // Well inside any character limit, four bytes each.
        $this->putJson('/api/me/metadata-credentials', ['metronToken' => str_repeat('🔑', 300)]);

        self::assertResponseStatusCodeSame(400);
    }

    /** A token right at the limit still round-trips through encryption. */
    public function testATokenAtTheLimitIsStored(): void
    {
        $this->loginAs(UserFactory::createOne()->object());
        $longest = str_repeat('a', AppDataEncryptionService::maxPlaintextBytes(1024));

        $response = $this->putJson('/api/me/metadata-credentials', ['metronToken' => $longest]);

        self::assertResponseIsSuccessful();
        self::assertTrue($response['configured']['metron']);
    }

    public function testANonStringTokenIsRejected(): void
    {
        $this->loginAs(UserFactory::createOne()->object());

        $this->putJson('/api/me/metadata-credentials', ['metronToken' => ['not', 'a', 'string']]);

        self::assertResponseStatusCodeSame(400);
    }

    /**
     * Nothing is configured and no token was typed, so this must say there is
     * nothing to test rather than reaching out. No network is touched.
     */
    public function testTestingWithNoTokenSaysSo(): void
    {
        $this->loginAs(UserFactory::createOne()->object());

        $response = $this->postJson('/api/me/metadata-credentials/verify', ['provider' => 'metron']);

        self::assertResponseIsSuccessful();
        self::assertSame('unconfigured', $response['result']['status']);
    }

    public function testTestingAnUnknownProviderIsRejected(): void
    {
        $this->loginAs(UserFactory::createOne()->object());

        $this->postJson('/api/me/metadata-credentials/verify', ['provider' => 'nope']);

        self::assertResponseStatusCodeSame(400);
    }

    /** Somebody with lookups withdrawn has nothing to test a token against. */
    public function testAUserWithoutApiAccessCannotTestAToken(): void
    {
        $this->loginAs(UserFactory::createOne(['metadataApiEnabled' => false])->object());

        $this->postJson('/api/me/metadata-credentials/verify', ['provider' => 'metron']);

        self::assertResponseStatusCodeSame(403);
    }

    /**
     * Whether a provider will answer them, in words they can act on — and
     * never why the installation's own credential was refused.
     */
    public function testSaysWhetherEachProviderWouldAnswer(): void
    {
        $this->loginAs(UserFactory::createOne()->object());

        $providers = $this->getJson('/api/me/metadata-credentials')['providers'];
        $byKey = array_column($providers, null, 'key');

        self::assertFalse($byKey['metron']['available']);
        self::assertSame('Metron is currently unavailable.', $byKey['metron']['reason']);
    }

    /**
     * An administrator can decide this server uses exactly one outbound
     * credential and knows which one it is.
     */
    public function testATokenCannotBeAddedWhenTheServerDoesNotAcceptThem(): void
    {
        $this->disablePersonalCredentials();
        $this->loginAs(UserFactory::createOne()->object());

        $this->putJson('/api/me/metadata-credentials', ['metronToken' => 'personal-metron-token']);

        self::assertResponseStatusCodeSame(403);
    }

    public function testATokenCannotBeTestedWhenTheServerDoesNotAcceptThem(): void
    {
        $this->disablePersonalCredentials();
        $this->loginAs(UserFactory::createOne()->object());

        $this->postJson('/api/me/metadata-credentials/verify', ['provider' => 'metron']);

        self::assertResponseStatusCodeSame(403);
    }

    /**
     * Switching it off stops a token being used; it does not throw it away.
     * Somebody turning it back on should not find everybody's token deleted.
     */
    public function testAStoredTokenSurvivesTheSwitchAndIsStillRemovable(): void
    {
        $user = UserFactory::createOne()->object();
        $this->loginAs($user);
        $this->putJson('/api/me/metadata-credentials', ['metronToken' => 'personal-metron-token']);

        $this->disablePersonalCredentials();
        $this->loginAs($user);

        $state = $this->getJson('/api/me/metadata-credentials');
        self::assertTrue($state['configured']['metron']);
        self::assertFalse($state['personalCredentialsEnabled']);

        // Removing is deliberately still allowed: a token that has stopped being
        // used is one somebody may well want off this server. Both routes to it
        // work — the per-field clear the panel uses, and the whole-record delete.
        $cleared = $this->putJson('/api/me/metadata-credentials', ['metronToken' => null]);
        self::assertResponseIsSuccessful();
        self::assertFalse($cleared['configured']['metron']);

        $after = $this->deleteJson('/api/me/metadata-credentials');
        self::assertResponseIsSuccessful();
        self::assertFalse($after['configured']['metron']);
    }

    private function disablePersonalCredentials(): void
    {
        $this->createAndLoginAdmin();
        $this->putJson('/api/admin/metadata-providers', ['personalCredentialsEnabled' => false]);
        self::assertResponseIsSuccessful();
    }
}
