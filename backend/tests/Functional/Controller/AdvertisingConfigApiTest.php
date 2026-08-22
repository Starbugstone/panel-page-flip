<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Service\AdvertisingConfiguration;
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
    }

    public function testAdvertisingIsOffAndNoPublisherIdIsPublishedByDefault(): void
    {
        $adsense = $this->getJson('/api/public-config')['adsense'];

        self::assertFalse($adsense['enabled']);
        self::assertNull($adsense['client']);
        self::assertFalse($adsense['testMode']);
    }

    /**
     * The publisher id is public by design; nothing else about the account is.
     * This guards the endpoint against growing a field that is not.
     */
    public function testNothingButTheEffectiveAdvertisingStateIsExposed(): void
    {
        $payload = $this->getJson('/api/public-config');

        self::assertSame(['adsense'], array_keys($payload));
        self::assertSame(['enabled', 'client', 'testMode'], array_keys($payload['adsense']));
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
        // Outside production every placement is a test placement, so a developer
        // pointing a real account at localhost records no impressions.
        self::assertTrue($adsense['testMode']);
    }

    private function enableAdvertising(string $client): void
    {
        static::getContainer()->set(
            AdvertisingConfiguration::class,
            new AdvertisingConfiguration(true, $client, 'test', new NullLogger())
        );
    }
}
