<?php

namespace App\Tests\Unit\Service;

use App\Enum\ProviderStatus;
use App\Service\ProviderCircuitBreaker;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * A pause, never a settings change.
 *
 * If a burst of failures flipped an administrator's switch off, somebody would
 * have to notice and turn it back on. These expire on their own instead.
 */
final class ProviderCircuitBreakerTest extends TestCase
{
    public function testAnAccountIsNotPausedToBeginWith(): void
    {
        self::assertNull($this->breaker()->pausedFor('metron.abc'));
    }

    /** What the provider asked for wins over anything computed here. */
    public function testRespectsTheDelayTheProviderAskedFor(): void
    {
        $breaker = $this->breaker();
        $now = new \DateTimeImmutable('2026-08-15 12:00:00');

        $breaker->recordFailure('metron.abc', ProviderStatus::RateLimited, 900, $now);

        self::assertSame(900, $breaker->pausedFor('metron.abc', $now));
    }

    public function testARefusedCredentialPausesImmediately(): void
    {
        $breaker = $this->breaker();

        $breaker->recordFailure('metron.abc', ProviderStatus::Unauthorized);

        self::assertNotNull($breaker->pausedFor('metron.abc'));
    }

    /** One timeout is weather. Five in ten minutes is a reason to stop. */
    public function testOccasionalFailuresDoNotPauseButARunOfThemDoes(): void
    {
        $breaker = $this->breaker();

        for ($i = 0; $i < 4; ++$i) {
            $breaker->recordFailure('metron.abc', ProviderStatus::Unreachable);
        }
        self::assertNull($breaker->pausedFor('metron.abc'));

        $breaker->recordFailure('metron.abc', ProviderStatus::Unreachable);
        self::assertNotNull($breaker->pausedFor('metron.abc'));
    }

    /** A working week must not accumulate into a pause. */
    public function testASuccessClearsTheFailureRun(): void
    {
        $breaker = $this->breaker();

        for ($i = 0; $i < 4; ++$i) {
            $breaker->recordFailure('metron.abc', ProviderStatus::Unreachable);
        }
        $breaker->recordSuccess('metron.abc');
        $breaker->recordFailure('metron.abc', ProviderStatus::Unreachable);

        self::assertNull($breaker->pausedFor('metron.abc'));
    }

    public function testThePauseExpiresOnItsOwn(): void
    {
        $breaker = $this->breaker();
        $now = new \DateTimeImmutable('2026-08-15 12:00:00');

        $breaker->recordFailure('metron.abc', ProviderStatus::RateLimited, 60, $now);

        self::assertSame(60, $breaker->pausedFor('metron.abc', $now));
        self::assertNull($breaker->pausedFor('metron.abc', $now->modify('+61 seconds')));
    }

    /** The pause belongs to one upstream account, not to every provider. */
    public function testPausingOneAccountLeavesAnotherAlone(): void
    {
        $breaker = $this->breaker();

        $breaker->recordFailure('metron.abc', ProviderStatus::Unauthorized);

        self::assertNull($breaker->pausedFor('metron.def'));
    }

    /** A provider asking for a week off does not get one. */
    public function testAnAbsurdRetryAfterIsCapped(): void
    {
        $breaker = $this->breaker();
        $now = new \DateTimeImmutable('2026-08-15 12:00:00');

        $breaker->recordFailure('metron.abc', ProviderStatus::RateLimited, 604_800, $now);

        self::assertSame(3_600, $breaker->pausedFor('metron.abc', $now));
    }

    private function breaker(): ProviderCircuitBreaker
    {
        return new ProviderCircuitBreaker($this->cache());
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
