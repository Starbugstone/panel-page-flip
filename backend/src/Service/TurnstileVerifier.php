<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Contracts\HttpClient\Exception\DecodingExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class TurnstileVerifier
{
    public const SITEVERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';
    public const ACTION = 'content_report';

    private const MAX_TOKEN_LENGTH = 2048;
    private const PROVIDER_ERROR_CODES = [
        'bad-request',
        'internal-error',
        'invalid-input-secret',
        'missing-input-secret',
    ];

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly TurnstileConfiguration $configuration,
    ) {
    }

    public function verify(mixed $token, ?string $remoteIp): void
    {
        if (!is_string($token)
            || ($token = trim($token)) === ''
            || strlen($token) > self::MAX_TOKEN_LENGTH) {
            throw new TurnstileRejectedException('The Turnstile token is missing or invalid.');
        }

        $body = [
            'secret' => $this->configuration->secretKey(),
            'response' => $token,
        ];
        if ($remoteIp !== null && $remoteIp !== '') {
            $body['remoteip'] = $remoteIp;
        }

        try {
            $response = $this->httpClient->request('POST', self::SITEVERIFY_URL, [
                'body' => $body,
                'timeout' => 5,
                'max_duration' => 10,
            ]);
            $status = $response->getStatusCode();
            if ($status < 200 || $status >= 300) {
                throw new TurnstileUnavailableException('Turnstile Siteverify returned an unavailable response.');
            }
            $result = $response->toArray(false);
        } catch (TransportExceptionInterface|DecodingExceptionInterface $exception) {
            throw new TurnstileUnavailableException('Turnstile Siteverify is unavailable.', previous: $exception);
        }

        $errorCodes = isset($result['error-codes']) && is_array($result['error-codes'])
            ? array_values(array_filter($result['error-codes'], 'is_string'))
            : [];
        if (array_intersect(self::PROVIDER_ERROR_CODES, $errorCodes) !== []) {
            throw new TurnstileUnavailableException('Turnstile Siteverify could not validate challenges.');
        }

        if (($result['success'] ?? null) !== true
            || ($result['action'] ?? null) !== self::ACTION
            || !is_string($result['hostname'] ?? null)
            || mb_strtolower(trim($result['hostname'])) !== $this->configuration->expectedHostname()) {
            throw new TurnstileRejectedException('Turnstile rejected the challenge.');
        }
    }
}
