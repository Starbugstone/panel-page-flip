<?php

declare(strict_types=1);

namespace App\Metadata\Provider;

/**
 * The answer to "do these credentials work", in terms an administrator can act
 * on.
 *
 * A refused key and an unreachable host both mean no results, but they call for
 * different things — one is a typo, the other is a network or an outage — so
 * they are never collapsed into a single failure.
 */
final class ProviderVerification implements \JsonSerializable
{
    private function __construct(
        public readonly string $status,
        public readonly string $message,
    ) {
    }

    public static function ok(string $message = 'Credentials accepted.'): self
    {
        return new self('ok', $message);
    }

    public static function unconfigured(string $message = 'No credentials to test.'): self
    {
        return new self('unconfigured', $message);
    }

    public static function unauthorized(string $message): self
    {
        return new self('unauthorized', $message);
    }

    public static function unreachable(string $message = 'The service could not be reached.'): self
    {
        return new self('unreachable', $message);
    }

    public static function rateLimited(string $message): self
    {
        return new self('rate_limited', $message);
    }

    public static function failed(string $message): self
    {
        return new self('failed', $message);
    }

    public function isOk(): bool
    {
        return $this->status === 'ok';
    }

    /** @return array<string, string> */
    public function jsonSerialize(): array
    {
        return ['status' => $this->status, 'message' => $this->message];
    }
}
