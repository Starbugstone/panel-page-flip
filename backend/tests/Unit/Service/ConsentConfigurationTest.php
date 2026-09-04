<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\AdvertisingConfiguration;
use App\Service\ConsentConfiguration;
use App\Service\GoogleAnalyticsConfiguration;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The whole configuration matrix, in one table.
 *
 * Two independent switches and two independent credentials make sixteen inputs
 * an operator can produce, and the interesting ones are not the tidy corners.
 * They are the states where a credential outlives the feature it belongs to, or
 * where one integration is misconfigured and the other is not — the two ways
 * this used to go wrong.
 */
final class ConsentConfigurationTest extends TestCase
{
    private const VALID_CLIENT = 'ca-pub-1234567890123456';

    private const VALID_MEASUREMENT_ID = 'G-PSW1MY7HB4';

    /**
     * @return iterable<string, array{bool, string, bool, string, bool, bool, string|null, string|null}>
     */
    public static function matrixProvider(): iterable
    {
        yield 'neither' => [false, '', false, '', false, false, null, null];
        yield 'AdSense only' => [true, self::VALID_CLIENT, false, '', true, false, 'google', self::VALID_CLIENT];
        yield 'Analytics only' => [false, '', true, self::VALID_MEASUREMENT_ID, false, true, 'local', null];
        // A leftover publisher id from an installation that has switched
        // advertising off. It must not select the Google CMP, and it must not
        // be published to the browser, or the frontend would load Funding
        // Choices for a deployment that deliberately turned advertising off.
        yield 'stray publisher id, Analytics on' => [false, self::VALID_CLIENT, true, self::VALID_MEASUREMENT_ID, false, true, 'local', null];
        yield 'both' => [true, self::VALID_CLIENT, true, self::VALID_MEASUREMENT_ID, true, true, 'google', self::VALID_CLIENT];
        // One integration's typo must cost only that integration.
        yield 'invalid publisher id, Analytics on' => [true, 'ca-pub-nope', true, self::VALID_MEASUREMENT_ID, false, true, 'local', null];
        yield 'invalid measurement id, AdSense on' => [true, self::VALID_CLIENT, true, 'UA-123456-1', true, false, 'google', self::VALID_CLIENT];
        yield 'both switched on with nothing configured' => [true, '', true, '', false, false, null, null];
    }

    /**
     * @dataProvider matrixProvider
     */
    public function testEveryEffectiveCombinationResolvesToOneConsentOwner(
        bool $adsEnabled,
        string $client,
        bool $analyticsEnabled,
        string $measurementId,
        bool $expectedAds,
        bool $expectedAnalytics,
        ?string $expectedProvider,
        ?string $expectedGoogleClient,
    ): void {
        $advertising = new AdvertisingConfiguration($adsEnabled, $client, new NullLogger());
        $analytics = new GoogleAnalyticsConfiguration($analyticsEnabled, $measurementId, new NullLogger());
        $consent = new ConsentConfiguration($advertising, $analytics);

        self::assertSame($expectedAds, $advertising->isEnabled(), 'effective advertising');
        self::assertSame($expectedAnalytics, $analytics->isEnabled(), 'effective analytics');
        self::assertSame($expectedProvider, $consent->provider(), 'consent provider');
        self::assertSame($expectedAnalytics, $consent->coversAnalytics(), 'consent covers analytics');
        self::assertSame($expectedGoogleClient, $consent->googleClient(), 'published publisher id');
        self::assertSame(
            [
                'provider' => $expectedProvider,
                'analytics' => $expectedAnalytics,
                'googleClient' => $expectedGoogleClient,
            ],
            $consent->publicConfiguration()
        );
    }

    public function testTheGoogleCmpCanOwnAdvertisingConsentAloneWhenAnalyticsIsOff(): void
    {
        $consent = new ConsentConfiguration(
            new AdvertisingConfiguration(true, self::VALID_CLIENT, new NullLogger()),
            new GoogleAnalyticsConfiguration(false, '', new NullLogger()),
        );

        self::assertSame('google', $consent->provider());
        self::assertFalse($consent->coversAnalytics());
    }
}
