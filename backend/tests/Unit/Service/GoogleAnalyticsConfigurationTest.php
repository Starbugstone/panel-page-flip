<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\AdvertisingConfiguration;
use App\Service\GoogleAnalyticsConfiguration;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class GoogleAnalyticsConfigurationTest extends TestCase
{
    public function testItIsOffByDefault(): void
    {
        $configuration = $this->configuration(false, '', '');

        self::assertFalse($configuration->isEnabled());
        self::assertSame(['enabled' => false, 'measurementId' => null], $configuration->publicConfiguration());
    }

    public function testItNeedsTheSwitchMeasurementIdAndCertifiedConsentPlatform(): void
    {
        self::assertFalse($this->configuration(false, 'G-PSW1MY7HB4', 'ca-pub-1234567890123456')->isEnabled());
        self::assertFalse($this->configuration(true, '', 'ca-pub-1234567890123456')->isEnabled());
        self::assertFalse($this->configuration(true, 'G-PSW1MY7HB4', '')->isEnabled());
        self::assertTrue($this->configuration(true, 'G-PSW1MY7HB4', 'ca-pub-1234567890123456')->isEnabled());
    }

    public function testItRejectsAnythingOtherThanAGa4MeasurementId(): void
    {
        foreach (['UA-123456-1', 'G-', 'G-invalid!', 'GT-PSW1MY7HB4', 'G-PSW1 MY7'] as $value) {
            self::assertFalse($this->configuration(true, $value, 'ca-pub-1234567890123456')->isEnabled(), $value);
        }
    }

    public function testAdvertisingCanStayOffWhileItsPublisherIdProvidesTheCmp(): void
    {
        $advertising = new AdvertisingConfiguration(false, 'ca-pub-1234567890123456', new NullLogger());
        $configuration = new GoogleAnalyticsConfiguration(true, 'G-PSW1MY7HB4', $advertising, new NullLogger());

        self::assertFalse($advertising->isEnabled());
        self::assertTrue($configuration->isEnabled());
        self::assertSame('ca-pub-1234567890123456', $configuration->consentClient());
    }

    private function configuration(bool $enabled, string $measurementId, string $client): GoogleAnalyticsConfiguration
    {
        return new GoogleAnalyticsConfiguration(
            $enabled,
            $measurementId,
            new AdvertisingConfiguration(false, $client, new NullLogger()),
            new NullLogger()
        );
    }
}
