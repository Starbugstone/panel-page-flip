<?php

namespace App\Tests\Unit\Metadata\Provider;

use App\Enum\ProviderStatus;
use App\Metadata\Provider\ComicVineProvider;
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

        $candidates = $provider->search(new ProviderQuery('Batman', '7'), self::access())->candidates;

        self::assertCount(1, $candidates);
        self::assertSame('comicvine', $candidates[0]->provider);
        self::assertSame('4000', $candidates[0]->externalId);
        self::assertSame('Batman', $candidates[0]->series);
        self::assertSame('7', $candidates[0]->issueNumber);
        self::assertSame('https://comicvine.example/cover.jpg', $candidates[0]->coverUrl);
    }

    public function testReadsTheFullRecordFromADetailLookup(): void
    {
        $provider = $this->provider(new MockResponse(json_encode([
            'status_code' => 1,
            'results' => [
                'id' => 4000,
                'issue_number' => '7',
                'name' => 'The Long Halloween',
                'deck' => 'A killer strikes on holidays.',
                'cover_date' => '1997-04-09',
                'volume' => ['name' => 'Batman', 'publisher' => ['name' => 'DC Comics']],
                'image' => ['original_url' => 'https://comicvine.example/cover.jpg'],
                'person_credits' => [
                    ['name' => 'Jeph Loeb', 'role' => 'writer'],
                    ['name' => 'Tim Sale', 'role' => 'artist, cover'],
                ],
                'character_credits' => [['name' => 'Batman'], ['name' => 'Catwoman']],
                'team_credits' => [['name' => 'Gotham City Police Department']],
                'location_credits' => [['name' => 'Gotham City']],
                'story_arc_credits' => [['name' => 'The Long Halloween']],
            ],
        ]) ?: ''));

        $candidate = $provider->detail('4000', self::access())->candidates[0];

        self::assertTrue($candidate->isDetailed);
        self::assertSame('DC Comics', $candidate->publisher);
        self::assertSame('A killer strikes on holidays.', $candidate->summary);
        self::assertSame(
            ['writer' => ['Jeph Loeb'], 'artist' => ['Tim Sale'], 'cover' => ['Tim Sale']],
            $candidate->creators
        );
        self::assertSame(['Batman', 'Catwoman'], $candidate->classification?->characters);
        self::assertSame(['Gotham City'], $candidate->classification?->locations);
        self::assertSame(['The Long Halloween'], $candidate->classification?->storyArcs);
    }

    /**
     * `description` is HTML. Taking it verbatim would land markup in the
     * description field for the user to clean up by hand.
     */
    public function testPrefersThePlainBlurbAndStripsMarkupFromTheFallback(): void
    {
        $provider = $this->provider(new MockResponse(json_encode([
            'status_code' => 1,
            'results' => [
                'id' => 4000,
                'volume' => ['name' => 'Batman'],
                'description' => '<p>A killer strikes on <em>holidays</em>.</p>',
            ],
        ]) ?: ''));

        self::assertSame(
            'A killer strikes on holidays.',
            $provider->detail('4000', self::access())->candidates[0]->summary
        );
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

        $result = $provider->search(new ProviderQuery('Batman'), self::access());

        self::assertSame(ProviderStatus::Unauthorized, $result->status);
        self::assertSame([], $result->candidates);
    }

    /** An in-body error is a failure, so it must not be cached as a result. */
    public function testDoesNotRememberAnInBodyError(): void
    {
        $calls = 0;
        $client = new MockHttpClient(function () use (&$calls): MockResponse {
            ++$calls;

            return new MockResponse('{"status_code":107}');
        });

        $provider = $this->provider(null, client: $client);
        $provider->search(new ProviderQuery('Batman'), self::access());
        $provider->search(new ProviderQuery('Batman'), self::access());

        self::assertSame(2, $calls);
    }

    public function testDoesNotAskWhenAccessWasDenied(): void
    {
        $calls = 0;
        $client = new MockHttpClient(function () use (&$calls): MockResponse {
            ++$calls;

            return new MockResponse('{"status_code":1,"results":[]}');
        });

        $denied = ProviderAccess::denied('comicvine', ProviderStatus::Disabled, 'Comic Vine is turned off.');
        $result = $this->provider(null, client: $client)->search(new ProviderQuery('Batman'), $denied);

        self::assertSame(0, $calls);
        self::assertSame(ProviderStatus::Disabled, $result->status);
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

    public function testIdentifiesItselfAndSendsTheKey(): void
    {
        $seen = null;
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$seen): MockResponse {
            $seen = ['url' => $url, 'headers' => $options['headers'] ?? []];

            return new MockResponse('{"status_code":1,"results":[]}');
        });

        $this->provider(null, client: $client)->search(new ProviderQuery('Batman', '7'), self::access());

        self::assertStringContainsString('api_key=comic-vine-key-placeholder', (string) $seen['url']);
        self::assertStringContainsString('resources=issue', (string) $seen['url']);
        self::assertNotEmpty(array_filter(
            $seen['headers'],
            static fn ($h) => str_starts_with(strtolower((string) $h), 'user-agent: panelpageflip')
        ));
    }

    /** @dataProvider verificationCases */
    public function testSaysWhatHappenedWhenCredentialsAreTested(mixed $body, int $httpCode, ProviderStatus $expected): void
    {
        $payload = is_array($body)
            ? json_encode(['status_code' => $body[0]] + (isset($body[1]) ? ['error' => $body[1]] : []))
            : $body;

        $provider = $this->provider(new MockResponse((string) $payload, ['http_code' => $httpCode]));

        self::assertSame($expected, $provider->verify('comic-vine-key-placeholder')->status);
    }

    public function verificationCases(): iterable
    {
        yield 'accepted' => [[1], 200, ProviderStatus::Ok];
        yield 'rejected key' => [[100, 'Invalid API Key'], 200, ProviderStatus::Unauthorized];
        yield 'key with no access' => [[102], 200, ProviderStatus::Unauthorized];
        yield 'rate limited' => [[107], 200, ProviderStatus::RateLimited];
        yield 'some other api error' => [[104, 'Filter Error'], 200, ProviderStatus::Failed];
        yield 'rejected with a status code' => ['', 401, ProviderStatus::Unauthorized];
        yield 'forbidden' => ['', 403, ProviderStatus::Unauthorized];
        yield 'rate limited by status code' => ['', 429, ProviderStatus::RateLimited];
        yield 'http failure' => ['', 503, ProviderStatus::Failed];
    }

    public function testSaysThereIsNothingToTestWithoutAKey(): void
    {
        $verification = $this->provider(null)->verify(null);

        self::assertSame(ProviderStatus::Unconfigured, $verification->status);
        self::assertStringContainsString('API key', $verification->message);
    }

    public function testDistinguishesAnUnreachableServiceFromARejectedKey(): void
    {
        $client = new MockHttpClient(static function (): never {
            throw new \Symfony\Component\HttpClient\Exception\TransportException('no route to host');
        });

        self::assertSame(ProviderStatus::Unreachable, $this->provider(null, client: $client)->verify('key')->status);
    }

    private function provider(?MockResponse $response, ?MockHttpClient $client = null): ComicVineProvider
    {
        $cache = $this->cache();

        return new ComicVineProvider(
            $client ?? new MockHttpClient($response ?? new MockResponse('{"status_code":1,"results":[]}')),
            new RateLimiterFactory(
                ['id' => 'test', 'policy' => 'fixed_window', 'limit' => 100, 'interval' => '1 hour'],
                new InMemoryStorage()
            ),
            $cache,
            new ProviderQuotaTracker($cache),
            new ProviderCircuitBreaker($cache),
        );
    }

    private static function access(): ProviderAccess
    {
        return ProviderAccess::granted('comicvine', 'shared', 'comic-vine-key-placeholder');
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
