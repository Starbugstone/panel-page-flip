<?php

namespace App\Tests\Unit\Service;

use App\Service\ProviderQuotaTracker;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Metron publishes what is left of an account's allowance in response headers
 * and says clients should read those rather than assume a fixed daily budget.
 * A local limiter cannot represent that, because the real ceiling belongs to
 * the token's account rather than to this server.
 */
final class ProviderQuotaTrackerTest extends TestCase
{
    public function testRecordsWhatTheProviderReportedAboutTheAccount(): void
    {
        $tracker = $this->tracker();
        $now = new \DateTimeImmutable('2026-08-15 12:00:00');

        $tracker->record('metron.abc', $this->response([
            'X-RateLimit-Limit' => '100',
            'X-RateLimit-Remaining' => '42',
            'X-RateLimit-Reset' => '600',
        ]), $now);

        $state = $tracker->state('metron.abc');

        self::assertSame(100, $state['limit']);
        self::assertSame(42, $state['remaining']);
        self::assertSame($now->getTimestamp() + 600, $state['resetsAt']);
    }

    /** A reset header is either an absolute timestamp or seconds from now. */
    public function testUnderstandsAnAbsoluteResetTimestamp(): void
    {
        $tracker = $this->tracker();
        $now = new \DateTimeImmutable('2026-08-15 12:00:00');
        $reset = $now->getTimestamp() + 3_600;

        $tracker->record('metron.abc', $this->response(['X-RateLimit-Reset' => (string) $reset]), $now);

        self::assertSame($reset, $tracker->state('metron.abc')['resetsAt']);
    }

    public function testAProviderThatSaysNothingRecordsNoQuota(): void
    {
        $tracker = $this->tracker();
        $tracker->record('metron.abc', $this->response([]));

        $state = $tracker->state('metron.abc');

        self::assertArrayNotHasKey('remaining', $state);
        self::assertArrayNotHasKey('limit', $state);
        self::assertSame(200, $state['status']);
    }

    /** Two people pasting the same token share one upstream allowance. */
    public function testQuotaIsKeptPerAccountRatherThanPerCaller(): void
    {
        $tracker = $this->tracker();
        $tracker->record('metron.abc', $this->response(['X-RateLimit-Remaining' => '5']));

        self::assertSame(5, $tracker->state('metron.abc')['remaining']);
        self::assertSame([], $tracker->state('metron.different'));
    }

    /** `Retry-After` is the provider's explicit instruction and wins. */
    public function testReadsARetryAfterDelayInSeconds(): void
    {
        self::assertSame(
            120,
            $this->tracker()->retryDelay($this->response(['Retry-After' => '120'], 429))
        );
    }

    /** Both forms are legal, and both turn up. */
    public function testReadsARetryAfterGivenAsAnHttpDate(): void
    {
        $now = new \DateTimeImmutable('2026-08-15 12:00:00', new \DateTimeZone('UTC'));
        $at = $now->modify('+90 seconds')->format(\DATE_RFC7231);

        self::assertSame(
            90,
            $this->tracker()->retryDelay($this->response(['Retry-After' => $at], 429), $now)
        );
    }

    /**
     * RFC 7231 spells GMT as a literal rather than a timezone directive, so a
     * parse that does not say UTC is read in the server's default zone. On a
     * server an hour ahead that turns a real delay into zero.
     */
    public function testAnHttpDateIsReadAsUtcWhateverTheServerTimezoneIs(): void
    {
        $previous = date_default_timezone_get();
        date_default_timezone_set('Europe/Paris');

        try {
            $now = new \DateTimeImmutable('2026-08-15 12:00:00', new \DateTimeZone('UTC'));
            $at = $now->modify('+90 seconds')->format(\DATE_RFC7231);

            self::assertSame(
                90,
                $this->tracker()->retryDelay($this->response(['Retry-After' => $at], 429), $now)
            );
        } finally {
            date_default_timezone_set($previous);
        }
    }

    public function testFallsBackToTheResetHeaderWhenThereIsNoRetryAfter(): void
    {
        $now = new \DateTimeImmutable('2026-08-15 12:00:00');

        self::assertSame(
            300,
            $this->tracker()->retryDelay($this->response(['X-RateLimit-Reset' => '300'], 429), $now)
        );
    }

    public function testSaysNothingWhenTheProviderGaveNoGuidance(): void
    {
        self::assertNull($this->tracker()->retryDelay($this->response([], 429)));
    }

    /** @param array<string, string> $headers */
    private function response(array $headers, int $status = 200): ResponseInterface
    {
        $client = new MockHttpClient(new MockResponse('{}', ['http_code' => $status, 'response_headers' => $headers]));

        return $client->request('GET', 'https://provider.example/');
    }

    private function tracker(): ProviderQuotaTracker
    {
        return new ProviderQuotaTracker($this->cache());
    }

    private function cache(): CacheInterface
    {
        return new class(new ArrayAdapter()) implements CacheInterface {
            public function __construct(private ArrayAdapter $adapter)
            {
            }

            public function get(string $key, callable $callback, ?float $beta = null, ?array &$metadata = null): mixed
            {
                return $this->adapter->get($key, $callback, $beta, $metadata);
            }

            public function delete(string $key): bool
            {
                return $this->adapter->delete($key);
            }
        };
    }
}
