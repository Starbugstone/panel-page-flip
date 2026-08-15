<?php

declare(strict_types=1);

namespace App\Service;

use App\Enum\ProviderStatus;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Stops asking a provider that has just told us to stop asking.
 *
 * A temporary pause, never a settings change: a refused token or a burst of
 * timeouts must not quietly flip an administrator's switch off, because they
 * would then have to notice and turn it back on. The pause expires on its own.
 *
 * Cache-backed rather than a column, because it is per-credential, short-lived
 * and worthless after a restart of the upstream service. Keyed by account so
 * one user's exhausted personal token cannot pause the shared one.
 */
final class ProviderCircuitBreaker
{
    /** Consecutive soft failures before we stop trying for a while. */
    private const FAILURE_THRESHOLD = 5;
    private const FAILURE_WINDOW_SECONDS = 600;

    private const PAUSE_AFTER_FAILURES_SECONDS = 300;
    private const PAUSE_AFTER_UNAUTHORIZED_SECONDS = 900;
    private const MAX_PAUSE_SECONDS = 3_600;

    public function __construct(
        private readonly CacheInterface $cache,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /** Seconds until this account may be asked again, or null if it may now. */
    public function pausedFor(string $accountKey, ?\DateTimeImmutable $now = null): ?int
    {
        $until = $this->state($accountKey)['pausedUntil'] ?? null;
        if (!is_int($until)) {
            return null;
        }

        $remaining = $until - ($now ?? new \DateTimeImmutable())->getTimestamp();

        return $remaining > 0 ? $remaining : null;
    }

    /**
     * A provider answered normally. Clears the failure run so an occasional
     * timeout in a healthy week never accumulates into a pause.
     */
    public function recordSuccess(string $accountKey): void
    {
        $this->cache->delete($this->cacheKey($accountKey));
    }

    /**
     * @param int|null $retryAfterSeconds what the provider itself asked for, if it said
     */
    public function recordFailure(
        string $accountKey,
        ProviderStatus $status,
        ?int $retryAfterSeconds = null,
        ?\DateTimeImmutable $now = null
    ): void {
        $now = $now ?? new \DateTimeImmutable();
        $state = $this->state($accountKey);

        // Whatever the provider asked for wins over anything computed here: it
        // knows its own quota and we are guessing. A delay of zero is not a
        // request to wait, though — treated as one it would pause for no time
        // at all *and* suppress the failure threshold below, so a provider
        // answering `Retry-After: 0` forever would never be backed off.
        $pause = match (true) {
            $retryAfterSeconds !== null && $retryAfterSeconds > 0 => min($retryAfterSeconds, self::MAX_PAUSE_SECONDS),
            $status === ProviderStatus::Unauthorized => self::PAUSE_AFTER_UNAUTHORIZED_SECONDS,
            $status === ProviderStatus::RateLimited => self::PAUSE_AFTER_FAILURES_SECONDS,
            default => null,
        };

        $failures = ($state['failures'] ?? 0) + 1;
        if ($pause === null && $failures >= self::FAILURE_THRESHOLD) {
            $pause = self::PAUSE_AFTER_FAILURES_SECONDS;
        }

        $pausedUntil = $pause !== null ? $now->getTimestamp() + $pause : ($state['pausedUntil'] ?? null);

        if ($pause !== null) {
            // The account key is a hash, so this is safe to log; the credential
            // it came from is not recoverable from it.
            $this->logger?->warning('Metadata provider paused after a failure.', [
                'account' => $accountKey,
                'status' => $status->value,
                'seconds' => $pause,
            ]);
        }

        $this->write($accountKey, [
            'failures' => $failures,
            'pausedUntil' => $pausedUntil,
            'lastStatus' => $status->value,
            'lastFailureAt' => $now->getTimestamp(),
        ]);
    }

    /** @return array<string, mixed> */
    public function state(string $accountKey): array
    {
        $state = $this->cache->get($this->cacheKey($accountKey), static function (ItemInterface $item): array {
            $item->expiresAfter(self::FAILURE_WINDOW_SECONDS);

            return [];
        });

        return is_array($state) ? $state : [];
    }

    /** @param array<string, mixed> $state */
    private function write(string $accountKey, array $state): void
    {
        $this->cache->delete($this->cacheKey($accountKey));
        $this->cache->get($this->cacheKey($accountKey), static function (ItemInterface $item) use ($state): array {
            $pausedUntil = $state['pausedUntil'] ?? null;
            $lifetime = is_int($pausedUntil)
                ? max(self::FAILURE_WINDOW_SECONDS, $pausedUntil - time())
                : self::FAILURE_WINDOW_SECONDS;
            $item->expiresAfter($lifetime);

            return $state;
        });
    }

    private function cacheKey(string $accountKey): string
    {
        return 'metadata.circuit.'.$accountKey;
    }
}
