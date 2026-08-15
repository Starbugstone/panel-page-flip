<?php

declare(strict_types=1);

namespace App\Metadata\Provider;

use App\Enum\ProviderStatus;

/**
 * The answer to "do these credentials work", in terms an administrator can act
 * on.
 *
 * A refused key and an unreachable host both mean no results, but they call for
 * different things — one is a typo, the other is a network or an outage — so
 * they are never collapsed into a single failure.
 *
 * Shares ProviderStatus with the search path rather than keeping a vocabulary of
 * its own. The two were describing the same six outcomes in the same six words,
 * and two lists that have to stay in step are one list that eventually does not.
 * Only a subset applies here: a verification is a live call somebody asked for,
 * so it is never `disabled`, `forbidden` or `paused`.
 */
final class ProviderVerification implements \JsonSerializable
{
    private function __construct(
        public readonly ProviderStatus $status,
        public readonly string $message,
    ) {
    }

    public static function ok(string $message = 'Credentials accepted.'): self
    {
        return new self(ProviderStatus::Ok, $message);
    }

    public static function unconfigured(string $message = 'No credentials to test.'): self
    {
        return new self(ProviderStatus::Unconfigured, $message);
    }

    public static function unauthorized(string $message): self
    {
        return new self(ProviderStatus::Unauthorized, $message);
    }

    public static function unreachable(string $message = 'The service could not be reached.'): self
    {
        return new self(ProviderStatus::Unreachable, $message);
    }

    public static function rateLimited(string $message): self
    {
        return new self(ProviderStatus::RateLimited, $message);
    }

    public static function failed(string $message): self
    {
        return new self(ProviderStatus::Failed, $message);
    }

    public function isOk(): bool
    {
        return $this->status === ProviderStatus::Ok;
    }

    /** @return array<string, string> */
    public function jsonSerialize(): array
    {
        return ['status' => $this->status->value, 'message' => $this->message];
    }
}
