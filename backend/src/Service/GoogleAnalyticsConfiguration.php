<?php

declare(strict_types=1);

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Fail-closed runtime configuration for privacy-first GA4 measurement.
 *
 * Two settings decide it and nothing else: the switch and the measurement id.
 * Analytics deliberately knows nothing about advertising — it used to require a
 * valid `ADSENSE_CLIENT` because Google's Privacy & Messaging was the only
 * consent provider wired up, which made a credential for one product silently
 * gate another. Consent is a separate question with its own service; see
 * {@see ConsentConfiguration}.
 *
 * An unusable measurement id disables Analytics and nothing else. A broken
 * advertising configuration cannot reach this class to take measurement down
 * with it.
 */
final class GoogleAnalyticsConfiguration
{
    private const MEASUREMENT_ID_PATTERN = '/^G-[A-Z0-9]{5,20}$/';

    private readonly bool $enabled;

    private readonly ?string $measurementId;

    private readonly bool $measurementIdConfigured;

    public function __construct(
        #[Autowire('%google_analytics_enabled%')]
        bool $googleAnalyticsEnabled,
        #[Autowire('%google_analytics_measurement_id%')]
        string $googleAnalyticsMeasurementId,
        LoggerInterface $logger,
    ) {
        $measurementId = strtoupper(trim($googleAnalyticsMeasurementId));
        $validMeasurementId = preg_match(self::MEASUREMENT_ID_PATTERN, $measurementId) === 1;

        if ($googleAnalyticsEnabled && !$validMeasurementId) {
            $logger->warning(
                'Google Analytics is enabled but GOOGLE_ANALYTICS_MEASUREMENT_ID is missing or invalid. '
                .'Analytics disabled; no measurement tag will be loaded. Advertising is unaffected.',
                ['expectedFormat' => 'G- followed by 5 to 20 uppercase letters or digits']
            );
        }

        $this->enabled = $googleAnalyticsEnabled && $validMeasurementId;
        $this->measurementId = $validMeasurementId ? $measurementId : null;
        $this->measurementIdConfigured = $measurementId !== '';
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /** Whether the configured value is a syntactically valid GA4 measurement id. */
    public function hasValidMeasurementId(): bool
    {
        return $this->measurementId !== null;
    }

    /** Whether GOOGLE_ANALYTICS_MEASUREMENT_ID holds anything at all; for the diagnostic. */
    public function hasConfiguredMeasurementId(): bool
    {
        return $this->measurementIdConfigured;
    }

    public function measurementId(): ?string
    {
        return $this->enabled ? $this->measurementId : null;
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
