<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/** Fail-closed runtime configuration for the optional content-report challenge. */
final class TurnstileConfiguration
{
    private readonly ?string $siteKey;

    private readonly ?string $secretKey;

    private readonly ?string $expectedHostname;

    public function __construct(
        #[Autowire('%turnstile_enabled%')]
        private readonly bool $enabled,
        #[Autowire('%turnstile_site_key%')]
        string $siteKey,
        #[Autowire('%turnstile_secret_key%')]
        string $secretKey,
        #[Autowire('%app_url%')]
        string $appUrl,
    ) {
        $this->siteKey = ($siteKey = trim($siteKey)) !== '' ? $siteKey : null;
        $this->secretKey = ($secretKey = trim($secretKey)) !== '' ? $secretKey : null;
        $hostname = parse_url(trim($appUrl), PHP_URL_HOST);
        $this->expectedHostname = is_string($hostname) && $hostname !== ''
            ? mb_strtolower($hostname)
            : null;

        if ($this->enabled && ($this->siteKey === null || $this->secretKey === null)) {
            throw new \InvalidArgumentException(
                'Turnstile is enabled but TURNSTILE_SITE_KEY or TURNSTILE_SECRET_KEY is missing.'
            );
        }

        if ($this->enabled && $this->expectedHostname === null) {
            throw new \InvalidArgumentException(
                'Turnstile is enabled but APP_URL does not contain a valid hostname.'
            );
        }
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function secretKey(): string
    {
        return $this->secretKey
            ?? throw new \LogicException('Turnstile has no configured secret key.');
    }

    public function expectedHostname(): string
    {
        return $this->expectedHostname
            ?? throw new \LogicException('Turnstile has no configured hostname.');
    }

    /** @return array{enabled: bool, siteKey: string|null} */
    public function publicConfiguration(): array
    {
        return [
            'enabled' => $this->enabled,
            'siteKey' => $this->enabled ? $this->siteKey : null,
        ];
    }
}
