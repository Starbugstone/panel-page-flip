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
            self::assertSame(['key', 'label', 'configured', 'enabled', 'quota'], array_keys($provider));
        }
    }

    /**
     * The environment's half of each switch is reported, so an administrator
     * can see why a toggle they turned on is not taking effect.
     *
     * The two defaults differ on purpose. Shared Metron spends a token this
     * installation owns, so it is opted into. Comic Vine is allowed unless
     * somebody switches it off: a self-hosted library is inside its
     * non-commercial terms, and shipping it disabled would make every operator
     * hunt for a switch to get behaviour they were already entitled to.
     */
    public function testReportsWhetherTheEnvironmentAllowsEachProvider(): void
    {
        $this->createAndLoginAdmin();

        $environment = $this->getJson('/api/admin/metadata-providers')['environment'];

        self::assertFalse($environment['metronSharedEnabled']);
        self::assertTrue($environment['comicVineEnabled']);
    }

    /** Configuring a key is enough; there is no second switch to find. */
    public function testComicVineIsEnabledOutOfTheBox(): void
    {
        $this->createAndLoginAdmin();

        $enabled = array_column($this->getJson('/api/admin/metadata-providers')['providers'], 'enabled', 'key');

        self::assertTrue($enabled['comicvine']);
        self::assertFalse($enabled['metron']);
    }

    /**
     * The point of the switch: a deployment that stops satisfying Comic Vine's
     * terms turns it off here rather than in a code change.
     */
    public function testAnAdministratorCanTurnComicVineOff(): void
    {
        $this->createAndLoginAdmin();

        $response = $this->putJson('/api/admin/metadata-providers', ['comicVineEnabled' => false]);

        self::assertResponseIsSuccessful();
        self::assertFalse(array_column($response['providers'], 'enabled', 'key')['comicvine']);
    }

    /** There is nowhere here to put a Metron account password any more. */
    public function testAMetronPasswordIsNotSomethingThatCanBeStored(): void
    {
        $this->createAndLoginAdmin();

        $this->putJson('/api/admin/metadata-providers', ['metronPassword' => 'metron-password-placeholder']);

        self::assertResponseIsSuccessful();
        $configured = array_column($this->getJson('/api/admin/metadata-providers')['providers'], 'configured', 'key');
        self::assertFalse($configured['metron']);
    }

    public function testTogglesAreStoredAndReported(): void
    {
        $this->createAndLoginAdmin();

        $this->putJson('/api/admin/metadata-providers', [
            'metronSharedEnabled' => true,
            'comicVineEnabled' => false,
        ]);
        $response = $this->putJson('/api/admin/metadata-providers', ['comicVineEnabled' => true]);

        self::assertResponseIsSuccessful();
        $enabled = array_column($response['providers'], 'enabled', 'key');
        self::assertTrue($enabled['metron']);
        self::assertTrue($enabled['comicvine']);
    }

    public function testARejectedToggleIsNotABoolean(): void
    {
        $this->createAndLoginAdmin();

        $this->putJson('/api/admin/metadata-providers', ['metronSharedEnabled' => 'yes']);

        self::assertResponseStatusCodeSame(400);
    }

    public function testStoresCredentialsAndReportsThemAsConfigured(): void
    {
        $this->createAndLoginAdmin();

        $this->putJson('/api/admin/metadata-providers', [
            'metronToken' => 'metron-token-placeholder',
            'comicVineApiKey' => 'comicvine-key-placeholder',
        ]);

        self::assertResponseIsSuccessful();
        $body = (string) $this->browser()->getResponse()->getContent();

        // Confirms the change without echoing the secrets back.
        self::assertStringNotContainsString('metron-token-placeholder', $body);
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

        $this->putJson('/api/admin/metadata-providers', ['metronToken' => 'metron-token-placeholder']);
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
