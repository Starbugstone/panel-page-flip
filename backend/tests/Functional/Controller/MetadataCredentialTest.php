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

        $this->putJson('/api/me/metadata-credentials', ['metronToken' => str_repeat('a', 600)]);

        self::assertResponseStatusCodeSame(400);
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

    /** The reason a search would be refused, in words the user can act on. */
    public function testSaysWhyNoProviderWouldAnswer(): void
    {
        $this->loginAs(UserFactory::createOne()->object());

        $providers = $this->getJson('/api/me/metadata-credentials')['providers'];
        $byKey = array_column($providers, null, 'key');

        self::assertFalse($byKey['metron']['available']);
        self::assertNotSame('', $byKey['metron']['message']);
    }
}
