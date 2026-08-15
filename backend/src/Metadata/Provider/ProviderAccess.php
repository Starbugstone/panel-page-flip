<?php

declare(strict_types=1);

namespace App\Metadata\Provider;

use App\Enum\ProviderStatus;

/**
 * Permission to spend one provider's allowance, and the secret to do it with.
 *
 * Resolved per user and per request rather than injected once, because whose
 * credential is being spent is now a question with more than one answer: a
 * personal token, the installation's shared one, or nothing at all.
 *
 * A denial carries the reason. "No candidates" and "you may not ask" look
 * identical in a result list otherwise, and only one of them is worth acting on.
 */
final class ProviderAccess
{
    private function __construct(
        public readonly string $provider,
        public readonly ProviderStatus $status,
        public readonly string $message,
        public readonly ?string $origin,
        private readonly ?string $secret,
    ) {
    }

    /**
     * @param 'personal'|'shared' $origin whose allowance a call would spend
     */
    public static function granted(string $provider, string $origin, string $secret): self
    {
        return new self($provider, ProviderStatus::Ok, '', $origin, $secret);
    }

    public static function denied(string $provider, ProviderStatus $status, string $message): self
    {
        return new self($provider, $status, $message, null, null);
    }

    public function isGranted(): bool
    {
        return $this->secret !== null;
    }

    /**
     * The secret itself. Only a provider adapter about to build a request has
     * any business calling this; nothing that can reach a response body or a
     * log should.
     */
    public function secret(): string
    {
        if ($this->secret === null) {
            throw new \LogicException('No credential was granted for '.$this->provider.'.');
        }

        return $this->secret;
    }

    /**
     * Which upstream account this spends against.
     *
     * Derived from the secret rather than from the local user, because two
     * local users pasting the same token share one daily allowance upstream and
     * would otherwise be tracked as though they each had their own. Hashed so
     * quota bookkeeping can be keyed, cached and logged without ever holding
     * the credential.
     */
    public function accountKey(): string
    {
        return $this->provider.'.'.hash('xxh128', (string) $this->secret);
    }
}
