<?php

namespace App\Tests\Unit\Metadata\Provider;

use App\Metadata\Provider\ComicVineProvider;
use App\Metadata\Provider\ProviderCredentials;
use App\Metadata\Provider\ProviderQuery;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;
use Symfony\Contracts\Cache\CacheInterface;

final class ComicVineProviderTest extends TestCase
{
    public function testTurnsResultsIntoCandidates(): void
    {
        $provider = $this->provider(new MockResponse(json_encode([
            'status_code' => 1,
            'results' => [[
                'id' => 4000,
                'issue_number' => '7',
                'name' => 'The Long Halloween',
                'deck' => 'A killer strikes on holidays.',
                'cover_date' => '1997-04-09',
                'volume' => ['name' => 'Batman'],
                'image' => ['original_url' => 'https://comicvine.example/cover.jpg'],
            ]],
        ]) ?: ''));

        $candidates = $provider->search(new ProviderQuery('Batman', '7'));

        self::assertCount(1, $candidates);
        self::assertSame('comicvine', $candidates[0]->provider);
        self::assertSame('4000', $candidates[0]->externalId);
        self::assertSame('Batman', $candidates[0]->series);
        self::assertSame('7', $candidates[0]->issueNumber);
        self::assertSame('https://comicvine.example/cover.jpg', $candidates[0]->coverUrl);
    }

    /**
     * Comic Vine answers 200 and puts the failure in the body, so the HTTP
     * status alone would report a rejected key as a successful empty search.
     */
    public function testTreatsAnInBodyErrorAsAFailure(): void
    {
        $provider = $this->provider(new MockResponse(json_encode([
            'status_code' => 100,
            'error' => 'Invalid API Key',
            'results' => [['id' => 1, 'volume' => ['name' => 'Batman']]],
        ]) ?: ''));

        self::assertSame([], $provider->search(new ProviderQuery('Batman')));
    }

    public function testSaysNothingWhenUnconfigured(): void
    {
        $provider = $this->provider(new MockResponse('{"status_code":1,"results":[]}'), configured: false);

        self::assertFalse($provider->isConfigured());
        self::assertSame([], $provider->search(new ProviderQuery('Batman')));
    }

    public function testDegradesToNothingWhenTheHostIsUnreachable(): void
    {
        $client = new MockHttpClient(static function (): never {
            throw new \Symfony\Component\HttpClient\Exception\TransportException('dns failure');
        });

        self::assertSame([], $this->provider(null, client: $client)->search(new ProviderQuery('Batman')));
    }

    /** Comic Vine refuses requests without a descriptive user agent. */
    public function testIdentifiesItselfAndSendsTheKey(): void
    {
        $seen = null;
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$seen): MockResponse {
            $seen = ['url' => $url, 'headers' => $options['headers'] ?? []];

            return new MockResponse('{"status_code":1,"results":[]}');
        });

        $this->provider(null, client: $client)->search(new ProviderQuery('Batman', '7'));

        self::assertStringContainsString('api_key=comicvine-key-placeholder', (string) $seen['url']);
        self::assertStringContainsString('resources=issue', (string) $seen['url']);
        self::assertNotEmpty(array_filter(
            $seen['headers'],
            static fn ($h) => str_contains(strtolower((string) $h), 'user-agent: panelpageflip')
        ));
    }

    private function provider(?MockResponse $response, bool $configured = true, ?MockHttpClient $client = null): ComicVineProvider
    {
        return new ComicVineProvider(
            $client ?? new MockHttpClient($response ?? new MockResponse('{"status_code":1,"results":[]}')),
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

            public function metronUsername(): ?string { return null; }
            public function metronPassword(): ?string { return null; }
            public function comicVineApiKey(): ?string { return $this->configured ? 'comicvine-key-placeholder' : null; }
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
