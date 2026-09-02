<?php

declare(strict_types=1);

namespace App\Service;

/** The fail-closed boundary for the optional Dropbox integration. */
final class DropboxConfiguration
{
    public const UNAVAILABLE_MESSAGE = 'Dropbox imports are not configured on this server.';

    private readonly ?string $appKey;

    private readonly ?string $appSecret;

    public function __construct(string $appKey, string $appSecret)
    {
        $this->appKey = ($appKey = trim($appKey)) !== '' ? $appKey : null;
        $this->appSecret = ($appSecret = trim($appSecret)) !== '' ? $appSecret : null;
    }

    public function isConfigured(): bool
    {
        return $this->appKey !== null && $this->appSecret !== null;
    }

    public function appKey(): string
    {
        return $this->appKey
            ?? throw new \LogicException(self::UNAVAILABLE_MESSAGE);
    }

    public function appSecret(): string
    {
        return $this->appSecret
            ?? throw new \LogicException(self::UNAVAILABLE_MESSAGE);
    }
}
