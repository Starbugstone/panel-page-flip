<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * What a provider last told us about how much allowance is left.
 *
 * Metron reports its remaining burst and sustained allowance in response
 * headers and says clients should read those rather than hard-code a daily
 * budget, which a fixed local limiter cannot represent: the real ceiling
 * depends on the account, and the account is the token's, not ours.
 *
 * Keyed by account rather than by user. Two people who paste the same token
 * share one upstream allowance, and tracking them separately would show each of
 * them a full budget while the account ran out.
 */
final class ProviderQuotaTracker
{
    private const RETENTION_SECONDS = 86_400;

    public function __construct(private readonly CacheInterface $cache)
    {
    }

    /**
     * Read whatever the response was willing to say. Providers disagree about
     * header names and some say nothing at all, so every field is optional and
     * an absent one is recorded as unknown rather than as zero.
     */
    public function record(string $accountKey, ResponseInterface $response, ?\DateTimeImmutable $now = null): void
    {
        $now = $now ?? new \DateTimeImmutable();

        try {
            $headers = $response->getHeaders(false);
            $status = $response->getStatusCode();
        } catch (\Throwable) {
            return;
        }

        $header = static function (string $name) use ($headers): ?string {
            $values = $headers[strtolower($name)] ?? [];

            return is_array($values) && $values !== [] ? (string) $values[0] : null;
        };

        $number = static function (?string $value): ?int {
            return $value !== null && is_numeric(trim($value)) ? (int) trim($value) : null;
        };

        $this->write($accountKey, array_filter([
            'remaining' => $number($header('X-RateLimit-Remaining')),
            'limit' => $number($header('X-RateLimit-Limit')),
            'resetsAt' => self::resetTimestamp($header('X-RateLimit-Reset'), $now),
            'retryAfter' => self::retryAfter($header('Retry-After'), $now),
            'status' => $status,
            'observedAt' => $now->getTimestamp(),
        ], static fn (mixed $value): bool => $value !== null));
    }

    /** @return array<string, mixed> */
    public function state(string $accountKey): array
    {
        $state = $this->cache->get($this->cacheKey($accountKey), static function (ItemInterface $item): array {
            $item->expiresAfter(self::RETENTION_SECONDS);

            return [];
        });

        return is_array($state) ? $state : [];
    }

    /**
     * How long a 429 asked us to wait, from either header that can carry it.
     *
     * `Retry-After` is the provider's explicit instruction and is preferred; a
     * reset timestamp is the fallback for providers that only publish that.
     */
    public function retryDelay(ResponseInterface $response, ?\DateTimeImmutable $now = null): ?int
    {
        $now = $now ?? new \DateTimeImmutable();

        try {
            $headers = $response->getHeaders(false);
        } catch (\Throwable) {
            return null;
        }

        $first = static function (string $name) use ($headers): ?string {
            $values = $headers[strtolower($name)] ?? [];

            return is_array($values) && $values !== [] ? (string) $values[0] : null;
        };

        $delay = self::retryAfter($first('Retry-After'), $now);
        if ($delay !== null) {
            return $delay;
        }

        $resetsAt = self::resetTimestamp($first('X-RateLimit-Reset'), $now);

        return $resetsAt !== null ? max(0, $resetsAt - $now->getTimestamp()) : null;
    }

    /**
     * `Retry-After` is either a delay in seconds or an HTTP date. Both are
     * legal and both turn up.
     */
    private static function retryAfter(?string $value, \DateTimeImmutable $now): ?int
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $value = trim($value);
        if (is_numeric($value)) {
            return max(0, (int) $value);
        }

        $at = \DateTimeImmutable::createFromFormat(\DATE_RFC7231, $value);

        return $at !== false ? max(0, $at->getTimestamp() - $now->getTimestamp()) : null;
    }

    /** A reset header is either an absolute timestamp or seconds from now. */
    private static function resetTimestamp(?string $value, \DateTimeImmutable $now): ?int
    {
        if ($value === null || !is_numeric(trim($value))) {
            return null;
        }

        $reset = (int) trim($value);

        // Anything small enough to be a duration is treated as one; a real
        // epoch second is far past this in any year this code runs in.
        return $reset > 1_000_000_000 ? $reset : $now->getTimestamp() + max(0, $reset);
    }

    /** @param array<string, mixed> $state */
    private function write(string $accountKey, array $state): void
    {
        $this->cache->delete($this->cacheKey($accountKey));
        $this->cache->get($this->cacheKey($accountKey), static function (ItemInterface $item) use ($state): array {
            $item->expiresAfter(self::RETENTION_SECONDS);

            return $state;
        });
    }

    private function cacheKey(string $accountKey): string
    {
        return 'metadata.quota.'.$accountKey;
    }
}
