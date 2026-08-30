<?php

declare(strict_types=1);

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Whether this installation shows advertising, decided once, on the server.
 *
 * Two settings reach an operator — `ADSENSE_ENABLED` and `ADSENSE_CLIENT` —
 * because everything else AdSense can be told (which formats run, which pages
 * are excluded, whether an Offerwall appears) is configured in the AdSense
 * account rather than here. Adding a `.env` value per placement would put the
 * same decision in two places and let them disagree.
 *
 * The browser is told the *result*, never the inputs: the frontend has no
 * business re-deriving "is advertising on" from environment variables it would
 * have to be handed a copy of. The publisher id is public by design — it is in
 * the page code Google issues — and nothing else about the account leaves here.
 *
 * Every unusable configuration resolves to "off". Advertising is an enhancement
 * to this application, so a missing or malformed client id costs the operator
 * their advertising and costs the user nothing.
 */
final class AdvertisingConfiguration
{
    /**
     * A publisher id as AdSense issues it: `ca-pub-` and sixteen digits.
     *
     * Checked because the value is pasted by hand into a deployment and lands
     * in a script URL. A typo that reaches Google is an advertising outage
     * nobody is watching for; a typo caught here is a line in the log.
     */
    private const CLIENT_PATTERN = '/^ca-pub-[0-9]{16}$/';

    private readonly ?string $client;

    private readonly bool $enabled;

    public function __construct(
        #[Autowire('%adsense_enabled%')]
        bool $adsenseEnabled,
        #[Autowire('%adsense_client%')]
        string $adsenseClient,
        LoggerInterface $logger,
    ) {
        $client = trim($adsenseClient);
        $isUsableClient = $client !== '' && preg_match(self::CLIENT_PATTERN, $client) === 1;

        if ($adsenseEnabled && !$isUsableClient) {
            $logger->warning(
                'AdSense is enabled but ADSENSE_CLIENT is missing or invalid. '
                .'Advertising disabled; all application functionality remains available.',
                ['expectedFormat' => 'ca-pub- followed by 16 digits']
            );
        }

        $this->enabled = $adsenseEnabled && $isUsableClient;
        $this->client = $isUsableClient ? $client : null;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /** Whether the configured value is a syntactically valid public publisher id. */
    public function hasValidClient(): bool
    {
        return $this->client !== null;
    }

    /** The publisher id, only once it is both configured and switched on. */
    public function client(): ?string
    {
        return $this->enabled ? $this->client : null;
    }

    /**
     * The whole of what the browser is told.
     *
     * Two keys, matching the two settings. There is deliberately no test-mode
     * flag: Auto Ads are configured entirely in the AdSense account and Google
     * exposes no way for a page to mark them as test placements, so a flag here
     * could only promise a safety it does not deliver. What protects a developer
     * trying the integration is leaving ADSENSE_ENABLED off — see
     * docs/advertising.md.
     *
     * @return array{enabled: bool, client: string|null}
     */
    public function publicConfiguration(): array
    {
        return [
            'enabled' => $this->enabled,
            'client' => $this->client(),
        ];
    }
}
