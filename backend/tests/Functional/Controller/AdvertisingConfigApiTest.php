<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Service\AdvertisingConfiguration;
use App\Service\ConsentConfiguration;
use App\Service\GoogleAnalyticsConfiguration;
use App\Service\TurnstileConfiguration;
use App\Tests\Functional\AbstractApiTestCase;
use Psr\Log\NullLogger;

/**
 * What the browser is told about advertising, and where.
 *
 * The test environment ships `ADSENSE_ENABLED=false`, which is also what every
 * development and ordinary self-hosted installation runs. So these tests assert
 * the shipped default first: nothing to load, nothing to consent to, and bulk
 * upload with no gate in front of it.
 */
final class AdvertisingConfigApiTest extends AbstractApiTestCase
{
    public function testTheRuntimeConfigurationIsReadableBeforeAnybodySignsIn(): void
    {
        $payload = $this->getJson('/api/public-config');

        self::assertResponseIsSuccessful();
        self::assertArrayHasKey('adsense', $payload);
        self::assertArrayHasKey('analytics', $payload);
        self::assertArrayHasKey('consent', $payload);
        self::assertArrayHasKey('turnstile', $payload);
    }

    public function testAnalyticsAndConsentAreOffByDefault(): void
    {
        $payload = $this->getJson('/api/public-config');

        self::assertSame(['enabled' => false, 'measurementId' => null], $payload['analytics']);
        self::assertSame(
            ['provider' => null, 'analytics' => false, 'googleClient' => null],
            $payload['consent']
        );
    }

    public function testAdvertisingIsOffAndNoPublisherIdIsPublishedByDefault(): void
    {
        $adsense = $this->getJson('/api/public-config')['adsense'];

        self::assertFalse($adsense['enabled']);
        self::assertNull($adsense['client']);
    }

    /**
     * The publisher id and the operator's published contact details are public
     * by design; nothing else here is. Deliberately brittle: this endpoint
     * answers anyone who asks, so a field added to it is a decision to publish
     * that field, and it should not be possible to make without editing a test
     * that says so.
     */
    public function testNothingButThePubliclyPublishedFactsAreExposed(): void
    {
        $payload = $this->getJson('/api/public-config');

        self::assertSame(['adsense', 'analytics', 'consent', 'turnstile', 'operator', 'privacyEmail', 'legalEmail'], array_keys($payload));
        self::assertSame(['enabled', 'client'], array_keys($payload['adsense']));
        self::assertSame(['enabled', 'measurementId'], array_keys($payload['analytics']));
        self::assertSame(['provider', 'analytics', 'googleClient'], array_keys($payload['consent']));
        self::assertSame(['enabled', 'siteKey'], array_keys($payload['turnstile']));
        self::assertSame(['enabled' => false, 'siteKey' => null], $payload['turnstile']);
        self::assertStringNotContainsString('secret', strtolower(json_encode($payload, JSON_THROW_ON_ERROR)));
    }

    public function testEnabledTurnstilePublishesOnlyItsSiteKey(): void
    {
        static::getContainer()->set(
            TurnstileConfiguration::class,
            new TurnstileConfiguration(true, 'public-site-key', 'private-secret-key', 'https://panel.example')
        );

        $payload = $this->getJson('/api/public-config');

        self::assertSame(
            ['enabled' => true, 'siteKey' => 'public-site-key'],
            $payload['turnstile']
        );
        self::assertStringNotContainsString('private-secret-key', json_encode($payload, JSON_THROW_ON_ERROR));
    }

    /**
     * The four effective states, as the browser is told them.
     *
     * `/api/public-config` is the contract the whole frontend consent story is
     * built on, so each state is asserted whole rather than field by field: a
     * key that quietly changes shape here changes what a provider believes
     * about consent.
     *
     * @dataProvider effectiveStateProvider
     *
     * @param array{enabled: bool, client: string|null}               $expectedAdsense
     * @param array{enabled: bool, measurementId: string|null}        $expectedAnalytics
     * @param array{provider: string|null, analytics: bool, googleClient: string|null} $expectedConsent
     */
    public function testEachEffectiveStateIsPublishedIndependently(
        bool $adsEnabled,
        string $client,
        bool $analyticsEnabled,
        string $measurementId,
        array $expectedAdsense,
        array $expectedAnalytics,
        array $expectedConsent,
    ): void {
        $this->configure($adsEnabled, $client, $analyticsEnabled, $measurementId);

        $payload = $this->getJson('/api/public-config');

        self::assertSame($expectedAdsense, $payload['adsense']);
        self::assertSame($expectedAnalytics, $payload['analytics']);
        self::assertSame($expectedConsent, $payload['consent']);
    }

    /** @return iterable<string, array<int, mixed>> */
    public static function effectiveStateProvider(): iterable
    {
        yield 'A: neither' => [
            false, '', false, '',
            ['enabled' => false, 'client' => null],
            ['enabled' => false, 'measurementId' => null],
            ['provider' => null, 'analytics' => false, 'googleClient' => null],
        ];
        yield 'B: AdSense only' => [
            true, 'ca-pub-1234567890123456', false, '',
            ['enabled' => true, 'client' => 'ca-pub-1234567890123456'],
            ['enabled' => false, 'measurementId' => null],
            ['provider' => 'google', 'analytics' => false, 'googleClient' => 'ca-pub-1234567890123456'],
        ];
        yield 'C: Analytics only, no publisher id at all' => [
            false, '', true, 'G-PSW1MY7HB4',
            ['enabled' => false, 'client' => null],
            ['enabled' => true, 'measurementId' => 'G-PSW1MY7HB4'],
            ['provider' => 'local', 'analytics' => true, 'googleClient' => null],
        ];
        yield 'C: Analytics only, with a stray publisher id' => [
            false, 'ca-pub-1234567890123456', true, 'G-PSW1MY7HB4',
            ['enabled' => false, 'client' => null],
            ['enabled' => true, 'measurementId' => 'G-PSW1MY7HB4'],
            ['provider' => 'local', 'analytics' => true, 'googleClient' => null],
        ];
        yield 'D: both' => [
            true, 'ca-pub-1234567890123456', true, 'G-PSW1MY7HB4',
            ['enabled' => true, 'client' => 'ca-pub-1234567890123456'],
            ['enabled' => true, 'measurementId' => 'G-PSW1MY7HB4'],
            ['provider' => 'google', 'analytics' => true, 'googleClient' => 'ca-pub-1234567890123456'],
        ];
        yield 'invalid publisher id cannot disable valid Analytics' => [
            true, 'ca-pub-nope', true, 'G-PSW1MY7HB4',
            ['enabled' => false, 'client' => null],
            ['enabled' => true, 'measurementId' => 'G-PSW1MY7HB4'],
            ['provider' => 'local', 'analytics' => true, 'googleClient' => null],
        ];
        yield 'invalid measurement id cannot disable valid advertising' => [
            true, 'ca-pub-1234567890123456', true, 'UA-123456-1',
            ['enabled' => true, 'client' => 'ca-pub-1234567890123456'],
            ['enabled' => false, 'measurementId' => null],
            ['provider' => 'google', 'analytics' => false, 'googleClient' => 'ca-pub-1234567890123456'],
        ];
    }

    /**
     * Analytics used to require a valid `ADSENSE_CLIENT` even with advertising
     * switched off, because Privacy & Messaging was the only consent provider
     * wired up. This is the configuration that used to silently measure nothing.
     */
    public function testAnalyticsOnlyNeedsNoAdSenseAccountAtAll(): void
    {
        $this->configure(false, '', true, 'G-PSW1MY7HB4');

        $payload = $this->getJson('/api/public-config');

        self::assertTrue($payload['analytics']['enabled']);
        self::assertSame('local', $payload['consent']['provider']);
        self::assertStringNotContainsString('ca-pub', json_encode($payload, JSON_THROW_ON_ERROR));
    }

    public function testAdsTxtIsAbsentWhereThereIsNoAuthorisedSeller(): void
    {
        $this->client->request('GET', '/ads.txt');

        self::assertResponseStatusCodeSame(404);
    }

    public function testAdsTxtNamesGoogleAsTheSellerForTheConfiguredPublisher(): void
    {
        $this->enableAdvertising('ca-pub-1234567890123456');

        $this->client->request('GET', '/ads.txt');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'text/plain; charset=UTF-8');
        self::assertSame(
            "google.com, pub-1234567890123456, DIRECT, f08c47fec0942fa0\n",
            $this->client->getResponse()->getContent()
        );
    }

    public function testAnEnabledInstallationPublishesItsPublisherIdAndNothingMore(): void
    {
        $this->enableAdvertising('ca-pub-1234567890123456');

        $adsense = $this->getJson('/api/public-config')['adsense'];

        self::assertTrue($adsense['enabled']);
        self::assertSame('ca-pub-1234567890123456', $adsense['client']);
        self::assertSame(['enabled', 'client'], array_keys($adsense));
    }

    private function enableAdvertising(string $client): void
    {
        $this->configure(true, $client, false, '');
    }

    private function configure(bool $adsEnabled, string $client, bool $analyticsEnabled, string $measurementId): void
    {
        $advertising = new AdvertisingConfiguration($adsEnabled, $client, new NullLogger());
        $analytics = new GoogleAnalyticsConfiguration($analyticsEnabled, $measurementId, new NullLogger());
        $container = static::getContainer();
        $container->set(AdvertisingConfiguration::class, $advertising);
        $container->set(GoogleAnalyticsConfiguration::class, $analytics);
        $container->set(ConsentConfiguration::class, new ConsentConfiguration($advertising, $analytics));
    }

    /**
     * The legal contact details used to have an endpoint of their own, which
     * meant the privacy and cookie pages made two anonymous round trips on the
     * same render for two halves of the same public answer.
     */
    public function testItAlsoCarriesTheOperatorContactDetails(): void
    {
        $payload = $this->getJson('/api/public-config');

        self::assertArrayHasKey('operator', $payload);
        self::assertArrayHasKey('privacyEmail', $payload);
        self::assertArrayHasKey('legalEmail', $payload);
        self::assertResponseIsSuccessful();
    }

}
