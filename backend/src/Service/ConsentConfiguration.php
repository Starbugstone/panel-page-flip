<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Who asks this installation's visitors for consent to optional Google storage.
 *
 * The two optional Google integrations are configured independently, but only
 * one of them may own the consent dialogue: two panels asking about overlapping
 * purposes is how somebody ends up accepting in one and rejecting in the other.
 * So the owner is derived here, once, from what is actually effective:
 *
 *   AdSense on   -> Google Privacy & Messaging (a certified CMP, which AdSense
 *                   requires for EEA/UK/Swiss ad traffic and which can gather
 *                   the analytics purpose for GA4 in the same message)
 *   Analytics on -> this application's own Analytics-only consent
 *   neither      -> nobody, because there is nothing optional to consent to
 *
 * Derived rather than configured: an operator switch would be a third place the
 * same decision could be written down, and the only value it could hold that
 * the two feature flags do not already imply is a wrong one. A `CONSENT_PROVIDER`
 * setting becomes worth having when there are interchangeable CMP products to
 * choose between, and not before.
 *
 * The publisher id is public by design, but it is published only when the Google
 * CMP is the provider. Handing it to the browser in the Analytics-only state
 * would invite a frontend to load Funding Choices for an installation that has
 * deliberately switched advertising off.
 */
final class ConsentConfiguration
{
    public const PROVIDER_GOOGLE = 'google';

    public const PROVIDER_LOCAL = 'local';

    public function __construct(
        private readonly AdvertisingConfiguration $advertising,
        private readonly GoogleAnalyticsConfiguration $analytics,
    ) {
    }

    /** `google`, `local`, or null when no optional Google service is effective. */
    public function provider(): ?string
    {
        if ($this->advertising->isEnabled()) {
            return self::PROVIDER_GOOGLE;
        }

        return $this->analytics->isEnabled() ? self::PROVIDER_LOCAL : null;
    }

    /** Whether the provider has an analytics decision to collect at all. */
    public function coversAnalytics(): bool
    {
        return $this->analytics->isEnabled();
    }

    /** The publisher id the Google CMP loads under, and only while it is the provider. */
    public function googleClient(): ?string
    {
        return $this->provider() === self::PROVIDER_GOOGLE ? $this->advertising->client() : null;
    }

    /** @return array{provider: string|null, analytics: bool, googleClient: string|null} */
    public function publicConfiguration(): array
    {
        return [
            'provider' => $this->provider(),
            'analytics' => $this->coversAnalytics(),
            'googleClient' => $this->googleClient(),
        ];
    }
}
