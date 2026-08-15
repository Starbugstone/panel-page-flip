<?php

namespace App\Tests\Unit\Metadata\Provider;

use App\Metadata\Provider\MetronProvider;
use App\Metadata\Provider\ProviderCredentials;
use App\Metadata\Provider\ProviderQuery;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Psr16Cache;
use Symfony\Contracts\Cache\CacheInterface;

final class MetronProviderTest extends TestCase
{
    public function testTurnsResultsIntoCandidates(): void
    {
        $provider = $this->provider(new MockResponse(json_encode([
            'results' => [[
                'id' => 42,
                'number' => '7',
                'issue' => 'The Long Halloween',
                'desc' => 'A killer strikes on holidays.',
                'cover_date' => '1997-04-09',
                'image' => 'https://metron.cloud/cover.jpg',
                'series' => ['name' => 'Batman', 'volume' => 1996],
                'publisher' => ['name' => 'DC'],
            ]],
        ]) ?: ''));

        $candidates = $provider->search(new ProviderQuery('Batman', '7'));

        self::assertCount(1, $candidates);
        self::assertSame('metron', $candidates[0]->provider);
        self::assertSame('42', $candidates[0]->externalId);
        self::assertSame('Batman', $candidates[0]->series);
        self::assertSame('7', $candidates[0]->issueNumber);
        self::assertSame(1996, $candidates[0]->volume);
        self::assertSame('DC', $candidates[0]->publisher);
        self::assertSame('1997-04-09', $candidates[0]->publishedAt?->format('Y-m-d'));
    }

    public function testSaysNothingWhenUnconfigured(): void
    {
        $provider = $this->provider(new MockResponse('{"results":[]}'), configured: false);

        self::assertFalse($provider->isConfigured());
        self::assertSame([], $provider->search(new ProviderQuery('Batman')));
    }

    /** A provider being down is not the user's problem, and not an exception. */
    public function testDegradesToNothingOnAFailedRequest(): void
    {
        self::assertSame([], $this->provider(new MockResponse('', ['http_code' => 500]))->search(new ProviderQuery('Batman')));
    }

    public function testDegradesToNothingWhenTheHostIsUnreachable(): void
    {
        $client = new MockHttpClient(static function (): never {
            throw new \Symfony\Component\HttpClient\Exception\TransportException('no route to host');
        });

        self::assertSame([], $this->provider(null, client: $client)->search(new ProviderQuery('Batman')));
    }

    public function testIgnoresResultsItCannotIdentify(): void
    {
        $provider = $this->provider(new MockResponse(json_encode([
            'results' => [
                ['number' => '7'],
                ['id' => 1, 'series' => ['name' => '']],
                ['id' => 2, 'series' => ['name' => 'Batman']],
                'not an object',
            ],
        ]) ?: ''));

        $candidates = $provider->search(new ProviderQuery('Batman'));

        self::assertCount(1, $candidates);
        self::assertSame('2', $candidates[0]->externalId);
    }

    /** The second lookup for the same question must not cost a second request. */
    public function testAsksOnceForTheSameQuestion(): void
    {
        $calls = 0;
        $client = new MockHttpClient(function () use (&$calls): MockResponse {
            ++$calls;

            return new MockResponse('{"results":[]}');
        });

        $provider = $this->provider(null, client: $client);
        $provider->search(new ProviderQuery('Batman', '7'));
        $provider->search(new ProviderQuery('batman', '7'));

        self::assertSame(1, $calls);
    }

    public function testSendsTheCredentialsAndTheQuery(): void
    {
        $seen = null;
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$seen): MockResponse {
            $seen = ['url' => $url, 'headers' => $options['headers'] ?? []];

            return new MockResponse('{"results":[]}');
        });

        $this->provider(null, client: $client)->search(new ProviderQuery('Batman', '7', 1997));

        self::assertStringContainsString('series_name=Batman', (string) $seen['url']);
        self::assertStringContainsString('number=7', (string) $seen['url']);
        self::assertStringContainsString('cover_year=1997', (string) $seen['url']);
        self::assertNotEmpty(array_filter($seen['headers'], static fn ($h) => str_starts_with(strtolower((string) $h), 'authorization:')));
    }

    /** @dataProvider verificationCases */
    public function testSaysWhatHappenedWhenCredentialsAreTested(int $httpCode, string $expected): void
    {
        $provider = $this->provider(new MockResponse('{"results":[]}', ['http_code' => $httpCode]));

        self::assertSame($expected, $provider->verify(self::credentials(true))->status);
    }

    public function verificationCases(): iterable
    {
        yield 'accepted' => [200, 'ok'];
        yield 'refused' => [401, 'unauthorized'];
        yield 'forbidden' => [403, 'unauthorized'];
        yield 'rate limited' => [429, 'rate_limited'];
        yield 'server error' => [500, 'failed'];
    }

    public function testSaysThereIsNothingToTestWithoutBothHalves(): void
    {
        $onlyUsername = new class implements \App\Metadata\Provider\ProviderCredentials {
            public function metronUsername(): ?string { return 'librarian'; }
            public function metronPassword(): ?string { return null; }
            public function comicVineApiKey(): ?string { return null; }
        };

        $verification = $this->provider(null)->verify($onlyUsername);

        self::assertSame('unconfigured', $verification->status);
        self::assertStringContainsString('username and a password', $verification->message);
    }

    public function testDistinguishesAnUnreachableServiceFromRefusedCredentials(): void
    {
        $client = new MockHttpClient(static function (): never {
            throw new \Symfony\Component\HttpClient\Exception\TransportException('no route to host');
        });

        self::assertSame('unreachable', $this->provider(null, client: $client)->verify(self::credentials(true))->status);
    }

    /** Verification asks the live service, so a cached search cannot answer it. */
    public function testVerificationIsNotServedFromTheSearchCache(): void
    {
        $calls = 0;
        $client = new MockHttpClient(function () use (&$calls): MockResponse {
            ++$calls;

            return new MockResponse('{"results":[]}');
        });

        $provider = $this->provider(null, client: $client);
        $provider->verify(self::credentials(true));
        $provider->verify(self::credentials(true));

        self::assertSame(2, $calls);
    }

    private function provider(?MockResponse $response, bool $configured = true, ?MockHttpClient $client = null): MetronProvider
    {
        return new MetronProvider(
            $client ?? new MockHttpClient($response ?? new MockResponse('{"results":[]}')),
            self::credentials($configured),
            new RateLimiterFactory(['id' => 'test', 'policy' => 'fixed_window', 'limit' => 100, 'interval' => '1 hour'], new InMemoryStorage()),
            $this->cache(),
        );
    }

    private static function credentials(bool $configured): ProviderCredentials
    {
        return new class($configured) implements ProviderCredentials {
            public function __construct(private bool $configured)
            {
            }

            public function metronUsername(): ?string { return $this->configured ? 'user' : null; }
            public function metronPassword(): ?string { return $this->configured ? 'metron-password-placeholder' : null; }
            public function comicVineApiKey(): ?string { return null; }
        };
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
