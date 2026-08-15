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
    /**
     * The payload here is a real row from Metron's issue list, keys and all.
     * The list carries no publisher and no description — those are on
     * /issue/{id}/ — so a candidate from a search cannot have them.
     */
    public function testTurnsResultsIntoCandidates(): void
    {
        $provider = $this->provider(new MockResponse(json_encode([
            'count' => 1,
            'results' => [[
                'id' => 123925,
                'series' => ['id' => 7969, 'name' => 'The Boys', 'volume' => 1, 'year_began' => 2006],
                'number' => '7',
                'issue' => 'The Boys (2006) #7',
                'cover_date' => '1997-04-09',
                'store_date' => null,
                'image' => 'https://static.metron.cloud/media/issue/cover.jpg',
                'cover_hash' => 'afd2e409a6187b66',
                'modified' => '2024-12-18T12:56:44.058359-05:00',
            ]],
        ]) ?: ''));

        $candidates = $provider->search(new ProviderQuery('The Boys', '7'));

        self::assertCount(1, $candidates);
        self::assertSame('metron', $candidates[0]->provider);
        self::assertSame('123925', $candidates[0]->externalId);
        self::assertSame('The Boys', $candidates[0]->series);
        self::assertSame('7', $candidates[0]->issueNumber);
        self::assertSame(1, $candidates[0]->volume);
        self::assertSame('The Boys (2006) #7', $candidates[0]->title);
        self::assertSame('1997-04-09', $candidates[0]->publishedAt?->format('Y-m-d'));
        self::assertSame('https://static.metron.cloud/media/issue/cover.jpg', $candidates[0]->coverUrl);

        // Not available from a search, and claimed by nothing.
        self::assertNull($candidates[0]->publisher);
        self::assertNull($candidates[0]->summary);
    }

    /**
     * Metron matches a series name loosely: asking for "The Boys" really does
     * return "Adventures of The Dover Boys" among 165 results. Whoever is
     * choosing should not have to scroll past it.
     */
    public function testPutsTheClosestSeriesMatchFirst(): void
    {
        $row = static fn (int $id, string $series): array => [
            'id' => $id,
            'series' => ['name' => $series, 'volume' => 1],
            'number' => '1',
            'cover_date' => '2006-01-01',
        ];

        $provider = $this->provider(new MockResponse(json_encode([
            'results' => [
                $row(1, 'Adventures of The Dover Boys'),
                $row(2, 'The Boys Presents'),
                $row(3, 'The Boys'),
                $row(4, 'Herogasm'),
            ],
        ]) ?: ''));

        $order = array_map(
            static fn ($candidate): string => $candidate->series,
            $provider->search(new ProviderQuery('The Boys'))
        );

        self::assertSame(
            ['The Boys', 'The Boys Presents', 'Adventures of The Dover Boys', 'Herogasm'],
            $order
        );
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
