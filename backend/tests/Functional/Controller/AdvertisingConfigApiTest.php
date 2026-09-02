<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Service\AdvertisingConfiguration;
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
        self::assertArrayHasKey('googleConsent', $payload);
        self::assertArrayHasKey('turnstile', $payload);
    }

    public function testAnalyticsAndConsentAreOffByDefault(): void
    {
        $payload = $this->getJson('/api/public-config');

        self::assertSame(['enabled' => false, 'measurementId' => null], $payload['analytics']);
        self::assertSame(['enabled' => false, 'client' => null], $payload['googleConsent']);
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

        self::assertSame(['adsense', 'analytics', 'googleConsent', 'turnstile', 'operator', 'privacyEmail', 'legalEmail'], array_keys($payload));
        self::assertSame(['enabled', 'client'], array_keys($payload['adsense']));
        self::assertSame(['enabled', 'measurementId'], array_keys($payload['analytics']));
        self::assertSame(['enabled', 'client'], array_keys($payload['googleConsent']));
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

    public function testAnalyticsCanUseTheCertifiedCmpWithoutEnablingAdvertising(): void
    {
        $advertising = new AdvertisingConfiguration(false, 'ca-pub-1234567890123456', new NullLogger());
        static::getContainer()->set(AdvertisingConfiguration::class, $advertising);
        static::getContainer()->set(
            GoogleAnalyticsConfiguration::class,
            new GoogleAnalyticsConfiguration(true, 'G-PSW1MY7HB4', $advertising, new NullLogger())
        );

        $payload = $this->getJson('/api/public-config');

        self::assertSame(['enabled' => false, 'client' => null], $payload['adsense']);
        self::assertSame(['enabled' => true, 'measurementId' => 'G-PSW1MY7HB4'], $payload['analytics']);
        self::assertSame(
            ['enabled' => true, 'client' => 'ca-pub-1234567890123456'],
            $payload['googleConsent']
        );
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
        static::getContainer()->set(
            AdvertisingConfiguration::class,
            new AdvertisingConfiguration(true, $client, new NullLogger())
        );
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
