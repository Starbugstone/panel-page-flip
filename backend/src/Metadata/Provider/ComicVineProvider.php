<?php

declare(strict_types=1);

namespace App\Metadata\Provider;

use Psr\Log\LoggerInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Comic Vine. Authenticates with an API key in the query string, and requires a
 * descriptive user agent — requests without one are refused.
 *
 * @see https://comicvine.gamespot.com/api/documentation
 */
final class ComicVineProvider implements MetadataProviderInterface
{
    private const BASE_URL = 'https://comicvine.gamespot.com/api';
    private const USER_AGENT = 'PanelPageFlip/1.0 (self-hosted comic library)';
    private const TIMEOUT_SECONDS = 10;
    private const MAX_RESULTS = 10;
    private const CACHE_TTL_SECONDS = 86_400;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly ProviderCredentials $credentials,
        private readonly RateLimiterFactory $metadataProviderLimiter,
        private readonly CacheInterface $cache,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    public function key(): string
    {
        return 'comicvine';
    }

    public function label(): string
    {
        return 'Comic Vine';
    }

    public function isConfigured(): bool
    {
        return $this->credentials->comicVineApiKey() !== null;
    }

    public function search(ProviderQuery $query): array
    {
        if (!$this->isConfigured()) {
            return [];
        }

        return $this->cache->get($query->cacheKey($this->key()), function (ItemInterface $item) use ($query): array {
            $item->expiresAfter(self::CACHE_TTL_SECONDS);

            if (!$this->metadataProviderLimiter->create($this->key())->consume()->isAccepted()) {
                $this->logger?->info('Comic Vine lookup skipped: rate limit reached.');
                $item->expiresAfter(60);

                return [];
            }

            return $this->request($query);
        });
    }

    public function verify(ProviderCredentials $candidate): ProviderVerification
    {
        $key = $candidate->comicVineApiKey();
        if ($key === null) {
            return ProviderVerification::unconfigured('Comic Vine needs an API key.');
        }

        try {
            $response = $this->httpClient->request('GET', self::BASE_URL.'/issues/', [
                'query' => ['api_key' => $key, 'format' => 'json', 'limit' => 1],
                'headers' => ['User-Agent' => self::USER_AGENT],
                'timeout' => self::TIMEOUT_SECONDS,
            ]);

            // Comic Vine rejects a key two different ways depending on the
            // endpoint: an HTTP 401 here, and a 200 carrying an error code
            // elsewhere. Both mean the same thing to whoever typed it, so both
            // are reported as a refused key rather than as "HTTP 401".
            if (in_array($response->getStatusCode(), [401, 403], true)) {
                return ProviderVerification::unauthorized('Comic Vine rejected the API key.');
            }

            if ($response->getStatusCode() === 429) {
                return ProviderVerification::rateLimited('Comic Vine is rate limiting this server. Try again shortly.');
            }

            if ($response->getStatusCode() !== 200) {
                return ProviderVerification::failed(sprintf('Comic Vine answered with HTTP %d.', $response->getStatusCode()));
            }

            $payload = $response->toArray(false);

            return match ((int) ($payload['status_code'] ?? 0)) {
                1 => ProviderVerification::ok('Comic Vine accepted the key.'),
                100, 102 => ProviderVerification::unauthorized('Comic Vine rejected the API key.'),
                107 => ProviderVerification::rateLimited('Comic Vine is rate limiting this server. Try again shortly.'),
                default => ProviderVerification::failed(sprintf(
                    'Comic Vine returned error %s: %s',
                    (string) ($payload['status_code'] ?? '?'),
                    (string) ($payload['error'] ?? 'no reason given')
                )),
            };
        } catch (\Throwable $exception) {
            $this->logger?->info('Comic Vine verification could not reach the service.', ['reason' => $exception->getMessage()]);

            return ProviderVerification::unreachable('Comic Vine could not be reached from this server.');
        }
    }

    /** @return list<ProviderCandidate> */
    private function request(ProviderQuery $query): array
    {
        $terms = trim($query->series.' '.($query->issueNumber ?? ''));

        try {
            $response = $this->httpClient->request('GET', self::BASE_URL.'/search/', [
                'query' => [
                    // Deliberately not logged: the key is a secret and the URL
                    // carries it.
                    'api_key' => $this->credentials->comicVineApiKey(),
                    'format' => 'json',
                    'resources' => 'issue',
                    'limit' => self::MAX_RESULTS,
                    'query' => $terms,
                ],
                'headers' => ['User-Agent' => self::USER_AGENT],
                'timeout' => self::TIMEOUT_SECONDS,
            ]);

            if ($response->getStatusCode() !== 200) {
                $this->logger?->info('Comic Vine lookup failed.', ['status' => $response->getStatusCode()]);

                return [];
            }

            $payload = $response->toArray(false);
        } catch (\Throwable $exception) {
            $this->logger?->info('Comic Vine could not be reached.', ['reason' => $exception->getMessage()]);

            return [];
        }

        // Comic Vine reports its own errors inside a 200 response.
        if ((int) ($payload['status_code'] ?? 0) !== 1) {
            $this->logger?->info('Comic Vine returned an error.', ['status_code' => $payload['status_code'] ?? null]);

            return [];
        }

        return $this->candidates($payload['results'] ?? []);
    }

    /**
     * @param mixed $results
     * @return list<ProviderCandidate>
     */
    private function candidates(mixed $results): array
    {
        if (!is_array($results)) {
            return [];
        }

        $candidates = [];

        foreach (array_slice($results, 0, self::MAX_RESULTS) as $result) {
            if (!is_array($result) || !isset($result['id'])) {
                continue;
            }

            $series = is_array($result['volume'] ?? null) ? (string) ($result['volume']['name'] ?? '') : '';
            if ($series === '') {
                continue;
            }

            $candidates[] = new ProviderCandidate(
                provider: $this->key(),
                externalId: (string) $result['id'],
                series: $series,
                issueNumber: isset($result['issue_number']) ? (string) $result['issue_number'] : null,
                title: isset($result['name']) ? (string) $result['name'] : null,
                summary: isset($result['deck']) ? (string) $result['deck'] : null,
                publishedAt: $this->date($result['cover_date'] ?? null),
                coverUrl: isset($result['image']['original_url']) ? (string) $result['image']['original_url'] : null,
            );
        }

        return $candidates;
    }

    private function date(mixed $value): ?\DateTimeImmutable
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        return \DateTimeImmutable::createFromFormat('!Y-m-d', $value) ?: null;
    }
}
