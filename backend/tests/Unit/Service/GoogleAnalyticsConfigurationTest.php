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
        $configuration = $this->configuration(false, '');

        self::assertFalse($configuration->isEnabled());
        self::assertSame(['enabled' => false, 'measurementId' => null], $configuration->publicConfiguration());
    }

    public function testItNeedsOnlyTheSwitchAndAMeasurementId(): void
    {
        self::assertFalse($this->configuration(false, 'G-PSW1MY7HB4')->isEnabled());
        self::assertFalse($this->configuration(true, '')->isEnabled());
        self::assertTrue($this->configuration(true, 'G-PSW1MY7HB4')->isEnabled());
    }

    public function testItRejectsAnythingOtherThanAGa4MeasurementId(): void
    {
        foreach (['UA-123456-1', 'G-', 'G-invalid!', 'GT-PSW1MY7HB4', 'G-PSW1 MY7'] as $value) {
            self::assertFalse($this->configuration(true, $value)->isEnabled(), $value);
        }
    }

    /**
     * The regression this class exists for. Analytics used to be switched off
     * unless `ADSENSE_CLIENT` held a valid publisher id, because Privacy &
     * Messaging was the only consent provider wired up — so an installation
     * that wanted measurement and no advertising had to configure an AdSense
     * account it would never use.
     */
    public function testItKnowsNothingAboutAdvertising(): void
    {
        $configuration = $this->configuration(true, 'G-PSW1MY7HB4');

        self::assertTrue($configuration->isEnabled());
        self::assertSame('G-PSW1MY7HB4', $configuration->measurementId());
        $dependencies = array_map(
            static fn (\ReflectionParameter $parameter): string => (string) $parameter->getType(),
            (new \ReflectionClass(GoogleAnalyticsConfiguration::class))->getConstructor()?->getParameters() ?? []
        );
        self::assertNotContains(AdvertisingConfiguration::class, $dependencies);
    }

    public function testTheDiagnosticCanTellAnUnsetIdFromAMistypedOne(): void
    {
        self::assertFalse($this->configuration(true, '')->hasConfiguredMeasurementId());
        self::assertTrue($this->configuration(true, 'UA-123456-1')->hasConfiguredMeasurementId());
        self::assertFalse($this->configuration(true, 'UA-123456-1')->hasValidMeasurementId());
        self::assertTrue($this->configuration(false, 'G-PSW1MY7HB4')->hasValidMeasurementId());
    }

    public function testTheMeasurementIdIsWithheldWhileTheSwitchIsOff(): void
    {
        $configuration = $this->configuration(false, 'G-PSW1MY7HB4');

        self::assertNull($configuration->measurementId());
        self::assertSame(['enabled' => false, 'measurementId' => null], $configuration->publicConfiguration());
    }

    private function configuration(bool $enabled, string $measurementId): GoogleAnalyticsConfiguration
    {
        return new GoogleAnalyticsConfiguration($enabled, $measurementId, new NullLogger());
    }
}
