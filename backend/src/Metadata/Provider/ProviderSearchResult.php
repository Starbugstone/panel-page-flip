<?php

declare(strict_types=1);

namespace App\Metadata\Provider;

use App\Enum\ProviderStatus;

/**
 * What one provider had to say, including the case where it said nothing.
 *
 * Replaces the bare candidate list the providers used to return. The list alone
 * could not distinguish a search that genuinely matched nothing from a provider
 * that was never asked, and the two need different words in front of the user.
 */
final class ProviderSearchResult implements \JsonSerializable
{
    /** @param list<ProviderCandidate> $candidates */
    private function __construct(
        public readonly string $provider,
        public readonly ProviderStatus $status,
        public readonly string $message,
        public readonly array $candidates,
        public readonly ?string $origin = null,
    ) {
    }

    /** @param list<ProviderCandidate> $candidates */
    public static function found(string $provider, array $candidates, ?string $origin = null): self
    {
        return new self($provider, ProviderStatus::Ok, '', $candidates, $origin);
    }

    public static function unavailable(string $provider, ProviderStatus $status, string $message, ?string $origin = null): self
    {
        return new self($provider, $status, $message, [], $origin);
    }

    public static function fromDeniedAccess(ProviderAccess $access): self
    {
        return new self($access->provider, $access->status, $access->message, []);
    }

    /**
     * The same answer, attributed to whoever is asking now.
     *
     * A cached result carries the origin of the caller that populated it, and
     * the cache key deliberately ignores who asked — that is how one person's
     * lookup saves another's allowance. The provenance is not shareable in the
     * same way: telling somebody their personal token was spent when the
     * installation's was is exactly the thing this field exists to get right.
     */
    public function withOrigin(?string $origin): self
    {
        return $origin === $this->origin
            ? $this
            : new self($this->provider, $this->status, $this->message, $this->candidates, $origin);
    }

    public function isOk(): bool
    {
        return $this->status === ProviderStatus::Ok;
    }

    /**
     * Whether this is worth remembering. A provider that was down or throttled
     * will have something different to say in a minute, and caching its silence
     * for a day turns a blip into a comic that "has no metadata".
     */
    public function isCacheable(): bool
    {
        return $this->status === ProviderStatus::Ok;
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'provider' => $this->provider,
            'status' => $this->status->value,
            'message' => $this->message,
            'origin' => $this->origin,
            'count' => count($this->candidates),
        ];
    }
}
