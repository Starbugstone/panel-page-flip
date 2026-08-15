<?php

namespace App\Tests\Unit\Metadata\Provider;

use App\Enum\ProviderStatus;
use App\Metadata\Provider\MetronProvider;
use App\Metadata\Provider\ProviderAccess;
use App\Metadata\Provider\ProviderQuery;
use App\Service\ProviderCircuitBreaker;
use App\Service\ProviderQuotaTracker;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;
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

        $result = $provider->search(new ProviderQuery('The Boys', '7'), self::access());
        $candidates = $result->candidates;

        self::assertSame(ProviderStatus::Ok, $result->status);
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
        self::assertFalse($candidates[0]->isDetailed);
    }

    /**
     * The detail endpoint is where everything worth reviewing lives, which is
     * the whole reason for fetching a record rather than only listing it.
     */
    public function testReadsTheFullRecordFromADetailLookup(): void
    {
        $provider = $this->provider(new MockResponse(json_encode([
            'id' => 123925,
            'publisher' => ['id' => 5, 'name' => 'Dynamite Entertainment'],
            'series' => ['name' => 'The Boys', 'volume' => 1, 'issue_count' => 72, 'genres' => [
                ['id' => 1, 'name' => 'Superhero'],
                ['id' => 2, 'name' => 'Crime'],
            ]],
            'number' => '7',
            'name' => 'Get Some',
            'cover_date' => '2006-11-01',
            'rating' => ['id' => 3, 'name' => 'Explicit'],
            'desc' => 'Hughie meets the Female.',
            'image' => 'https://static.metron.cloud/media/issue/cover.jpg',
            'credits' => [
                ['id' => 1, 'creator' => 'Garth Ennis', 'role' => [['id' => 1, 'name' => 'Writer']]],
                ['id' => 2, 'creator' => 'Darick Robertson', 'role' => [['id' => 2, 'name' => 'Penciller']]],
            ],
            'characters' => [['name' => 'Billy Butcher'], ['name' => 'Hughie Campbell']],
            'teams' => [['name' => 'The Boys']],
            'arcs' => [['name' => 'Get Some']],
        ]) ?: ''));

        $candidate = $provider->detail('123925', self::access())->candidates[0];

        self::assertTrue($candidate->isDetailed);
        self::assertSame('Dynamite Entertainment', $candidate->publisher);
        self::assertSame('Hughie meets the Female.', $candidate->summary);
        self::assertSame(72, $candidate->issueCount);
        self::assertSame('Explicit', $candidate->ageRating);
        self::assertSame(['writer' => ['Garth Ennis'], 'penciller' => ['Darick Robertson']], $candidate->creators);
        self::assertSame(['Superhero', 'Crime'], $candidate->classification?->genres);
        self::assertSame(['Billy Butcher', 'Hughie Campbell'], $candidate->classification?->characters);
        self::assertSame(['The Boys'], $candidate->classification?->teams);
        self::assertSame(['Get Some'], $candidate->classification?->storyArcs);
    }

    /** A record reference that could not have come from us never reaches a URL. */
    public function testRefusesAnExternalIdThatIsNotAnIdentifier(): void
    {
        $calls = 0;
        $client = new MockHttpClient(function () use (&$calls): MockResponse {
            ++$calls;

            return new MockResponse('{}');
        });

        $result = $this->provider(null, client: $client)->detail('../../series/1', self::access());

        self::assertSame(0, $calls);
        self::assertSame(ProviderStatus::Failed, $result->status);
    }

    /** Metron authenticates with a token now, never an account password. */
    public function testSendsTheTokenAndTheQuery(): void
    {
        $seen = null;
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$seen): MockResponse {
            $seen = ['url' => $url, 'headers' => $options['headers'] ?? []];

            return new MockResponse('{"results":[]}');
        });

        $this->provider(null, client: $client)->search(new ProviderQuery('Batman', '7', 1997), self::access());

        self::assertStringContainsString('series_name=Batman', (string) $seen['url']);
        self::assertStringContainsString('number=7', (string) $seen['url']);
        self::assertStringContainsString('cover_year=1997', (string) $seen['url']);
        self::assertContains('Authorization: Token metron-token-placeholder', $seen['headers']);
    }

    /** Access is decided before a request, so a denial costs nothing upstream. */
    public function testDoesNotAskWhenAccessWasDenied(): void
    {
        $calls = 0;
        $client = new MockHttpClient(function () use (&$calls): MockResponse {
            ++$calls;

            return new MockResponse('{"results":[]}');
        });

        $denied = ProviderAccess::denied('metron', ProviderStatus::Disabled, 'An administrator turned it off.');
        $result = $this->provider(null, client: $client)->search(new ProviderQuery('Batman'), $denied);

        self::assertSame(0, $calls);
        self::assertSame(ProviderStatus::Disabled, $result->status);
        self::assertSame('An administrator turned it off.', $result->message);
    }

    /**
     * @dataProvider failureCases
     *
     * A provider being down is not the user's problem and not an exception, but
     * it is also not the same answer as "no such comic".
     */
    public function testDistinguishesFailuresFromAnEmptyResult(int $httpCode, ProviderStatus $expected): void
    {
        $result = $this->provider(new MockResponse('', ['http_code' => $httpCode]))
            ->search(new ProviderQuery('Batman'), self::access());

        self::assertSame($expected, $result->status);
        self::assertSame([], $result->candidates);
        self::assertNotSame('', $result->message);
    }

    public function failureCases(): iterable
    {
        yield 'refused token' => [401, ProviderStatus::Unauthorized];
        yield 'forbidden' => [403, ProviderStatus::Unauthorized];
        yield 'throttled' => [429, ProviderStatus::RateLimited];
        yield 'server error' => [500, ProviderStatus::Failed];
    }

    public function testDegradesToAResultWhenTheHostIsUnreachable(): void
    {
        $client = new MockHttpClient(static function (): never {
            throw new \Symfony\Component\HttpClient\Exception\TransportException('no route to host');
        });

        $result = $this->provider(null, client: $client)->search(new ProviderQuery('Batman'), self::access());

        self::assertSame(ProviderStatus::Unreachable, $result->status);
        self::assertSame([], $result->candidates);
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

        $candidates = $provider->search(new ProviderQuery('Batman'), self::access())->candidates;

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
        $provider->search(new ProviderQuery('Batman', '7'), self::access());
        $provider->search(new ProviderQuery('batman', '7'), self::access());

        self::assertSame(1, $calls);
    }

    /**
     * A thirty-second outage must not become a comic that permanently has no
     * match. Only an answer is worth remembering.
     */
    public function testDoesNotRememberAFailure(): void
    {
        $calls = 0;
        $client = new MockHttpClient(function () use (&$calls): MockResponse {
            ++$calls;

            return new MockResponse('', ['http_code' => 503]);
        });

        $provider = $this->provider(null, client: $client);
        $provider->search(new ProviderQuery('Batman'), self::access());
        $provider->search(new ProviderQuery('Batman'), self::access());

        self::assertSame(2, $calls);
    }

    /** The local ceiling is reported as a rate limit, not as an empty result. */
    public function testSaysSoWhenTheLocalCeilingIsReached(): void
    {
        $provider = $this->provider(
            new MockResponse('{"results":[]}'),
            limiter: new RateLimiterFactory(
                ['id' => 'test', 'policy' => 'fixed_window', 'limit' => 1, 'interval' => '1 hour'],
                new InMemoryStorage()
            )
        );

        $provider->search(new ProviderQuery('Batman'), self::access());
        $second = $provider->search(new ProviderQuery('Superman'), self::access());

        self::assertSame(ProviderStatus::RateLimited, $second->status);
    }

    /** Repeated refusals stop the asking rather than being retried forever. */
    public function testARefusedTokenPausesTheAccount(): void
    {
        $cache = $this->cache();
        $breaker = new ProviderCircuitBreaker($cache);
        $provider = $this->provider(new MockResponse('', ['http_code' => 401]), breaker: $breaker);

        $provider->search(new ProviderQuery('Batman'), self::access());

        self::assertNotNull($breaker->pausedFor(self::access()->accountKey()));
    }

    /** @dataProvider verificationCases */
    public function testSaysWhatHappenedWhenATokenIsTested(int $httpCode, ProviderStatus $expected): void
    {
        $provider = $this->provider(new MockResponse('{"results":[]}', ['http_code' => $httpCode]));

        self::assertSame($expected, $provider->verify('metron-token-placeholder')->status);
    }

    public function verificationCases(): iterable
    {
        yield 'accepted' => [200, ProviderStatus::Ok];
        yield 'refused' => [401, ProviderStatus::Unauthorized];
        yield 'forbidden' => [403, ProviderStatus::Unauthorized];
        yield 'rate limited' => [429, ProviderStatus::RateLimited];
        yield 'server error' => [500, ProviderStatus::Failed];
    }

    public function testSaysThereIsNothingToTestWithoutAToken(): void
    {
        $verification = $this->provider(null)->verify(null);

        self::assertSame(ProviderStatus::Unconfigured, $verification->status);
        self::assertStringContainsString('token', $verification->message);
    }

    public function testDistinguishesAnUnreachableServiceFromARefusedToken(): void
    {
        $client = new MockHttpClient(static function (): never {
            throw new \Symfony\Component\HttpClient\Exception\TransportException('no route to host');
        });

        self::assertSame(ProviderStatus::Unreachable, $this->provider(null, client: $client)->verify('token')->status);
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
        $provider->verify('token');
        $provider->verify('token');

        self::assertSame(2, $calls);
    }

    private function provider(
        ?MockResponse $response,
        ?MockHttpClient $client = null,
        ?RateLimiterFactory $limiter = null,
        ?ProviderCircuitBreaker $breaker = null
    ): MetronProvider {
        $cache = $this->cache();

        return new MetronProvider(
            $client ?? new MockHttpClient($response ?? new MockResponse('{"results":[]}')),
            $limiter ?? new RateLimiterFactory(
                ['id' => 'test', 'policy' => 'fixed_window', 'limit' => 100, 'interval' => '1 hour'],
                new InMemoryStorage()
            ),
            $cache,
            new ProviderQuotaTracker($cache),
            $breaker ?? new ProviderCircuitBreaker($cache),
        );
    }

    private static function access(): ProviderAccess
    {
        return ProviderAccess::granted('metron', 'shared', 'metron-token-placeholder');
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
