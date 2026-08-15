<?php

namespace App\Tests\Functional\Controller;

use App\Service\AppDataEncryptionService;
use App\Tests\Functional\AbstractApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Provider credentials are the installation's secrets. The panel is told
 * whether a provider is configured, never what it was configured with.
 */
final class MetadataProviderAdminTest extends AbstractApiTestCase
{
    public function testReportsWhichProvidersAreConfiguredWithoutRevealingKeys(): void
    {
        $this->createAndLoginAdmin();

        $providers = $this->getJson('/api/admin/metadata-providers')['providers'];

        self::assertResponseIsSuccessful();
        self::assertSame(['comicvine', 'metron'], $this->sortedKeys($providers));
        foreach ($providers as $provider) {
            self::assertFalse($provider['configured']);
            self::assertSame(['key', 'label', 'configured'], array_keys($provider));
        }
    }

    public function testStoresCredentialsAndReportsThemAsConfigured(): void
    {
        $this->createAndLoginAdmin();

        $this->putJson('/api/admin/metadata-providers', [
            'metronUsername' => 'librarian',
            'metronPassword' => 'metron-password-placeholder',
            'comicVineApiKey' => 'comicvine-key-placeholder',
        ]);

        self::assertResponseIsSuccessful();
        $body = (string) $this->browser()->getResponse()->getContent();

        // Confirms the change without echoing the secrets back.
        self::assertStringNotContainsString('metron-password-placeholder', $body);
        self::assertStringNotContainsString('comicvine-key-placeholder', $body);

        $configured = array_column(json_decode($body, true)['providers'], 'configured', 'key');
        self::assertTrue($configured['metron']);
        self::assertTrue($configured['comicvine']);
    }

    public function testKeepsCredentialsEncryptedAtRest(): void
    {
        $this->createAndLoginAdmin();
        $this->putJson('/api/admin/metadata-providers', ['comicVineApiKey' => 'comicvine-key-placeholder']);
        self::assertResponseIsSuccessful();

        $stored = self::getContainer()->get(EntityManagerInterface::class)
            ->getConnection()
            ->fetchOne('SELECT comic_vine_api_key FROM metadata_provider_configuration WHERE id = 1');

        $encryption = self::getContainer()->get(AppDataEncryptionService::class);
        self::assertIsString($stored);
        self::assertNotSame('comicvine-key-placeholder', $stored);
        self::assertTrue($encryption->isEncrypted($stored));
        self::assertSame('comicvine-key-placeholder', $encryption->decrypt($stored));
    }

    public function testClearsACredentialWhenGivenNull(): void
    {
        $this->createAndLoginAdmin();

        $this->putJson('/api/admin/metadata-providers', ['comicVineApiKey' => 'comicvine-key-placeholder']);
        $response = $this->putJson('/api/admin/metadata-providers', ['comicVineApiKey' => null]);

        self::assertResponseIsSuccessful();
        self::assertFalse(array_column($response['providers'], 'configured', 'key')['comicvine']);
    }

    public function testLeavesUnmentionedCredentialsAlone(): void
    {
        $this->createAndLoginAdmin();

        $this->putJson('/api/admin/metadata-providers', ['metronUsername' => 'librarian', 'metronPassword' => 'metron-password-placeholder']);
        $response = $this->putJson('/api/admin/metadata-providers', ['comicVineApiKey' => 'comicvine-key-placeholder']);

        $configured = array_column($response['providers'], 'configured', 'key');
        self::assertTrue($configured['metron']);
        self::assertTrue($configured['comicvine']);
    }

    public function testRejectsACredentialThatIsNotAString(): void
    {
        $this->createAndLoginAdmin();

        $this->putJson('/api/admin/metadata-providers', ['comicVineApiKey' => ['not', 'a', 'string']]);

        self::assertResponseStatusCodeSame(400);
    }

    public function testANonAdministratorMayNotRead(): void
    {
        $this->createAndLoginUser();

        $this->getJson('/api/admin/metadata-providers');

        self::assertResponseStatusCodeSame(403);
    }

    public function testANonAdministratorMayNotWrite(): void
    {
        $this->createAndLoginUser();

        $this->putJson('/api/admin/metadata-providers', ['comicVineApiKey' => 'x']);

        self::assertResponseStatusCodeSame(403);
    }

    /**
     * Nothing is configured, so both should say there is nothing to test rather
     * than reaching out and failing. No network is touched.
     */
    public function testTestingWithNothingConfiguredSaysSo(): void
    {
        $this->createAndLoginAdmin();

        $results = $this->postJson('/api/admin/metadata-providers/verify')['results'];

        self::assertResponseIsSuccessful();
        self::assertSame(['comicvine', 'metron'], $this->sortedKeys($results));
        foreach ($results as $result) {
            self::assertSame('unconfigured', $result['status']);
            self::assertNotSame('', $result['message']);
        }
    }

    /** Results describe what happened; they never quote the credential back. */
    public function testTestResultsDoNotEchoTheCredential(): void
    {
        $this->createAndLoginAdmin();

        $this->postJson('/api/admin/metadata-providers/verify', ['comicVineApiKey' => 'comicvine-key-placeholder']);

        self::assertStringNotContainsString(
            'comicvine-key-placeholder',
            (string) $this->browser()->getResponse()->getContent()
        );
    }

    public function testTestingIsAdministratorsOnly(): void
    {
        $this->createAndLoginUser();

        $this->postJson('/api/admin/metadata-providers/verify', ['comicVineApiKey' => 'x']);

        self::assertResponseStatusCodeSame(403);
    }

    /** Testing must never store anything — that is what Save is for. */
    public function testTestingDoesNotStoreTheCredential(): void
    {
        $this->createAndLoginAdmin();

        $this->postJson('/api/admin/metadata-providers/verify', ['comicVineApiKey' => 'comicvine-key-placeholder']);

        $configured = array_column(
            $this->getJson('/api/admin/metadata-providers')['providers'],
            'configured',
            'key'
        );
        self::assertFalse($configured['comicvine']);
    }

    /** @param array<int, array<string, mixed>> $providers */
    private function sortedKeys(array $providers): array
    {
        $keys = array_column($providers, 'key');
        sort($keys);

        return $keys;
    }
}
