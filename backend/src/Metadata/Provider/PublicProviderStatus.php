<?php

declare(strict_types=1);

namespace App\Metadata\Provider;

use App\Enum\ProviderStatus;

/**
 * What a normal user is told about a provider.
 *
 * Deliberately much less than MetadataAccessResolver decided. The resolver's
 * answer names which account would be spent and why a shared one was refused;
 * that is operator diagnostics, and the installation's fallback account is a
 * backend implementation detail rather than something every account holder
 * should be able to enumerate.
 *
 * So: whether the provider will answer *them*, and a reason only when the
 * reason is theirs to act on. A user may be told their own token was rejected,
 * because it is their token. They are not told that the server has no token, or
 * that an administrator switched the shared one off.
 *
 * One inference survives and cannot be removed: a user with no personal token
 * whose search works can conclude the server has some way of doing it. That is
 * a consequence of the feature existing. Confirming the credential's state on
 * top of it is not.
 */
final class PublicProviderStatus implements \JsonSerializable
{
    private function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly bool $available,
        public readonly ?string $reason,
    ) {
    }

    public static function fromAccess(string $key, string $label, ProviderAccess $access): self
    {
        return new self($key, $label, $access->isGranted(), self::reason($label, $access->status, $access->origin));
    }

    public static function fromResult(string $key, string $label, ProviderSearchResult $result): self
    {
        return new self($key, $label, $result->isOk(), self::reason($label, $result->status, $result->origin));
    }

    /**
     * A reason a user can act on, or a flat statement that the provider is not
     * answering.
     *
     * Everything that distinguishes "no shared token", "an administrator turned
     * it off", "we are waiting out a rate limit" and "the host is unreachable"
     * collapses to one sentence on purpose. Those differences describe the
     * installation's account, and telling them apart is what lets somebody map
     * the server's configuration by reading error messages.
     */
    private static function reason(string $label, ProviderStatus $status, ?string $origin): ?string
    {
        return match (true) {
            $status === ProviderStatus::Ok => null,
            // About them, not about the server.
            $status === ProviderStatus::Forbidden => 'External metadata lookups are turned off for your account.',
            // Their own credential, so they are the one who can fix it.
            $status === ProviderStatus::Unauthorized && $origin === 'personal' =>
                sprintf('%s rejected your token. Check it in your settings.', $label),
            default => sprintf('%s is currently unavailable.', $label),
        };
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return array_filter([
            'key' => $this->key,
            'label' => $this->label,
            'available' => $this->available,
            'reason' => $this->reason,
        ], static fn (mixed $value): bool => $value !== null);
    }
}
