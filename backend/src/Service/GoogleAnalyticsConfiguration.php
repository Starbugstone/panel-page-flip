<?php

declare(strict_types=1);

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/** Fail-closed runtime configuration for privacy-first GA4 measurement. */
final class GoogleAnalyticsConfiguration
{
    private const MEASUREMENT_ID_PATTERN = '/^G-[A-Z0-9]{5,20}$/';

    private readonly bool $enabled;

    private readonly ?string $measurementId;

    public function __construct(
        #[Autowire('%google_analytics_enabled%')]
        bool $googleAnalyticsEnabled,
        #[Autowire('%google_analytics_measurement_id%')]
        string $googleAnalyticsMeasurementId,
        private readonly AdvertisingConfiguration $advertising,
        LoggerInterface $logger,
    ) {
        $measurementId = strtoupper(trim($googleAnalyticsMeasurementId));
        $validMeasurementId = preg_match(self::MEASUREMENT_ID_PATTERN, $measurementId) === 1;
        $hasConsentPlatform = $advertising->consentClient() !== null;

        if ($googleAnalyticsEnabled && !$validMeasurementId) {
            $logger->warning(
                'Google Analytics is enabled but GOOGLE_ANALYTICS_MEASUREMENT_ID is missing or invalid. '
                .'Analytics disabled; no measurement tag will be loaded.',
                ['expectedFormat' => 'G- followed by 5 to 20 uppercase letters or digits']
            );
        }

        if ($googleAnalyticsEnabled && $validMeasurementId && !$hasConsentPlatform) {
            $logger->warning(
                'Google Analytics is enabled but ADSENSE_CLIENT does not contain a valid Google publisher id. '
                .'Analytics disabled because the certified consent platform cannot be loaded.'
            );
        }

        $this->enabled = $googleAnalyticsEnabled && $validMeasurementId && $hasConsentPlatform;
        $this->measurementId = $validMeasurementId ? $measurementId : null;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function measurementId(): ?string
    {
        return $this->enabled ? $this->measurementId : null;
    }

    public function consentClient(): ?string
    {
        return $this->enabled ? $this->advertising->consentClient() : null;
    }

    /** @return array{enabled: bool, measurementId: string|null} */
    public function publicConfiguration(): array
    {
        return [
            'enabled' => $this->enabled,
            'measurementId' => $this->measurementId(),
        ];
    }
}
