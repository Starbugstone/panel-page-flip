<?php

namespace App\Tests\Functional\Controller;

use App\Entity\Comic;
use App\Tests\Factory\ComicFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Functional\AbstractApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * What a normal user can learn about the installation's own provider accounts.
 *
 * The secret itself was never at risk — provider calls are backend to backend.
 * The looser boundary was everything around it: whether a shared credential
 * exists, whether an administrator switched it off, and which account a given
 * search would spend. That is operator configuration, and an account holder
 * being able to enumerate it by reading status fields and error messages is not
 * something the feature needs.
 *
 * Distinctive sentinels are used so a failure names itself rather than showing
 * up as a diff of two JSON blobs.
 */
final class ProviderSecrecyTest extends AbstractApiTestCase
{
    private const METRON_SENTINEL = 'SERVER_METRON_SECRET_DO_NOT_LEAK_4bf19c';
    private const COMICVINE_SENTINEL = 'SERVER_COMICVINE_SECRET_DO_NOT_LEAK_8ac302';

    /**
     * Markers that describe the *server's* credential rather than the user's.
     *
     * `origin` is the sharp one: it named which account a request would spend.
     */
    private const OPERATOR_MARKERS = [
        // Which account a request would spend. The comic's own provenance is
        // `metadataOrigin`, which is the user's data and deliberately not this.
        '"origin":',
        'shared token',
        'shared credential',
        'server\'s credentials',
        'administrator',
        'No Metron token is configured',
        'No Comic Vine API key is configured',
    ];

    /** @dataProvider normalUserEndpoints */
    public function testANormalUserSeesNoServerCredentialState(string $method, string $path, array $payload): void
    {
        $this->configureSharedCredentials();

        $owner = UserFactory::createOne()->object();
        $comic = ComicFactory::createOne(['owner' => $owner, 'series' => 'The Boys'])->object();

        // Set through the paired setter — provider and external id are stored
        // together on purpose, so there is no individual one for a factory.
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->find(Comic::class, $comic->getId())->setMetadataOrigin('metron', '123925');
        $entityManager->flush();

        $this->loginAs($owner);

        $url = str_replace('{id}', (string) $comic->getId(), $path);
        $method === 'GET' ? $this->getJson($url) : $this->postJson($url, $payload);

        $body = (string) $this->browser()->getResponse()->getContent();

        self::assertStringNotContainsString(self::METRON_SENTINEL, $body, $url);
        self::assertStringNotContainsString(self::COMICVINE_SENTINEL, $body, $url);

        foreach (self::OPERATOR_MARKERS as $marker) {
            self::assertStringNotContainsStringIgnoringCase(
                $marker,
                $body,
                sprintf('%s leaks the server credential marker "%s".', $url, $marker)
            );
        }
    }

    public function normalUserEndpoints(): iterable
    {
        yield 'config' => ['GET', '/api/config', []];
        yield 'personal credentials' => ['GET', '/api/me/metadata-credentials', []];
        yield 'suggestions' => ['GET', '/api/comics/{id}/metadata-suggestions', []];
        yield 'candidates' => ['POST', '/api/comics/{id}/metadata-candidates', []];
        yield 'candidates, provider chosen' => ['POST', '/api/comics/{id}/metadata-candidates', ['provider' => 'metron']];
        yield 'record' => ['POST', '/api/comics/{id}/metadata-record', ['provider' => 'metron', 'externalId' => '123925']];
        yield 'refresh' => ['POST', '/api/comics/{id}/metadata-refresh', []];
    }

    /**
     * The provider list tells a user what they can use, and nothing about how
     * the server arranged it.
     */
    public function testTheProviderDescriptorIsReducedToWhatTheUserNeeds(): void
    {
        $this->configureSharedCredentials();
        $this->loginAs(UserFactory::createOne()->object());

        foreach ($this->getJson('/api/config')['metadataProviders'] as $provider) {
            self::assertSame(
                [],
                array_diff(array_keys($provider), ['key', 'label', 'available', 'reason']),
                'The public provider descriptor grew a field.'
            );
            self::assertArrayNotHasKey('origin', $provider);
            self::assertArrayNotHasKey('status', $provider);
            self::assertArrayNotHasKey('configured', $provider);
        }
    }

    /**
     * The one reason a user is given in their own terms, because it is their
     * own token that was rejected and theirs to replace.
     */
    public function testAUserIsToldWhenTheirOwnAccountIsTheReason(): void
    {
        $this->loginAs(UserFactory::createOne(['metadataApiEnabled' => false])->object());

        $providers = $this->getJson('/api/config')['metadataProviders'];
        $reasons = array_column($providers, 'reason');

        self::assertNotEmpty(array_filter($reasons, static fn (?string $r): bool => $r !== null && str_contains($r, 'your account')));
    }

    /**
     * Switched off, unconfigured, throttled and unreachable all read the same
     * to a user. Telling them apart is what maps the server's configuration.
     */
    public function testEveryServerSideRefusalReadsTheSame(): void
    {
        $this->loginAs(UserFactory::createOne()->object());
        $withoutAnything = array_column($this->getJson('/api/config')['metadataProviders'], 'reason', 'key');

        $this->configureSharedCredentials();
        $this->loginAs(UserFactory::createOne()->object());
        $configuredButOff = array_column($this->getJson('/api/config')['metadataProviders'], 'reason', 'key');

        self::assertSame(
            $withoutAnything['metron'],
            $configuredButOff['metron'],
            'A user can tell a configured-but-disabled provider from an unconfigured one.'
        );
    }

    /** The administrator's view is unchanged: they are the operator. */
    public function testAnAdministratorStillSeesConfigurationState(): void
    {
        $this->configureSharedCredentials();

        $providers = $this->getJson('/api/admin/metadata-providers')['providers'];
        $configured = array_column($providers, 'configured', 'key');

        self::assertTrue($configured['metron']);
        self::assertTrue($configured['comicvine']);
    }

    /** Even for an administrator, the secret itself never comes back. */
    public function testTheAdministratorViewStillWithholdsTheSecret(): void
    {
        $this->configureSharedCredentials();

        $this->getJson('/api/admin/metadata-providers');
        $body = (string) $this->browser()->getResponse()->getContent();

        self::assertStringNotContainsString(self::METRON_SENTINEL, $body);
        self::assertStringNotContainsString(self::COMICVINE_SENTINEL, $body);
    }

    /** A user's own credential state stays visible to them, and only them. */
    public function testAUserStillSeesTheirOwnCredentialState(): void
    {
        $owner = UserFactory::createOne()->object();
        $this->loginAs($owner);
        $this->putJson('/api/me/metadata-credentials', ['metronToken' => 'personal-token']);

        self::assertTrue($this->getJson('/api/me/metadata-credentials')['configured']['metron']);

        $this->loginAs(UserFactory::createOne()->object());
        self::assertFalse($this->getJson('/api/me/metadata-credentials')['configured']['metron']);
    }

    private function configureSharedCredentials(): void
    {
        $this->createAndLoginAdmin();
        $this->putJson('/api/admin/metadata-providers', [
            'metronToken' => self::METRON_SENTINEL,
            'comicVineApiKey' => self::COMICVINE_SENTINEL,
            'metronSharedEnabled' => true,
            'comicVineEnabled' => true,
        ]);
        self::assertResponseIsSuccessful();
    }
}
