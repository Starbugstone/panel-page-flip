<?php

declare(strict_types=1);

namespace App\Metadata\Provider;

use Psr\Log\LoggerInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Metron, a community comic database. Authenticates with HTTP Basic.
 *
 * @see https://metron.cloud/
 */
final class MetronProvider implements MetadataProviderInterface
{
    private const BASE_URL = 'https://metron.cloud/api';
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
        return 'metron';
    }

    public function label(): string
    {
        return 'Metron';
    }

    public function isConfigured(): bool
    {
        return $this->credentials->metronUsername() !== null && $this->credentials->metronPassword() !== null;
    }

    public function search(ProviderQuery $query): array
    {
        if (!$this->isConfigured()) {
            return [];
        }

        return $this->cache->get($query->cacheKey($this->key()), function (ItemInterface $item) use ($query): array {
            $item->expiresAfter(self::CACHE_TTL_SECONDS);

            if (!$this->metadataProviderLimiter->create($this->key())->consume()->isAccepted()) {
                $this->logger?->info('Metron lookup skipped: rate limit reached.');
                $item->expiresAfter(60);

                return [];
            }

            return $this->request($query);
        });
    }

    public function verify(ProviderCredentials $candidate): ProviderVerification
    {
        $username = $candidate->metronUsername();
        $password = $candidate->metronPassword();

        if ($username === null || $password === null) {
            return ProviderVerification::unconfigured('Metron needs both a username and a password.');
        }

        try {
            // The cheapest authenticated call there is: one row of a list that
            // always exists. Enough to prove the credentials, and small enough
            // to be polite about asking.
            $response = $this->httpClient->request('GET', self::BASE_URL.'/series/', [
                'auth_basic' => [$username, $password],
                'query' => ['page' => 1],
                'timeout' => self::TIMEOUT_SECONDS,
            ]);

            return match (true) {
                $response->getStatusCode() === 200 => ProviderVerification::ok('Metron accepted the credentials.'),
                in_array($response->getStatusCode(), [401, 403], true) => ProviderVerification::unauthorized(
                    'Metron refused the username or password.'
                ),
                $response->getStatusCode() === 429 => ProviderVerification::rateLimited(
                    'Metron is rate limiting this server. The credentials may still be fine; try again shortly.'
                ),
                default => ProviderVerification::failed(
                    sprintf('Metron answered with HTTP %d.', $response->getStatusCode())
                ),
            };
        } catch (\Throwable $exception) {
            $this->logger?->info('Metron verification could not reach the service.', ['reason' => $exception->getMessage()]);

            return ProviderVerification::unreachable('Metron could not be reached from this server.');
        }
    }

    /** @return list<ProviderCandidate> */
    private function request(ProviderQuery $query): array
    {
        $parameters = ['series_name' => $query->series];
        if ($query->issueNumber !== null) $parameters['number'] = $query->issueNumber;
        if ($query->year !== null) $parameters['cover_year'] = $query->year;

        try {
            $response = $this->httpClient->request('GET', self::BASE_URL.'/issue/', [
                'auth_basic' => [$this->credentials->metronUsername(), $this->credentials->metronPassword()],
                'query' => $parameters,
                'timeout' => self::TIMEOUT_SECONDS,
            ]);

            if ($response->getStatusCode() !== 200) {
                $this->logger?->info('Metron lookup failed.', ['status' => $response->getStatusCode()]);

                return [];
            }

            $payload = $response->toArray(false);
        } catch (\Throwable $exception) {
            // Never surfaced to the user: an unreachable provider must not turn
            // into a broken page for somebody editing a comic.
            $this->logger?->info('Metron could not be reached.', ['reason' => $exception->getMessage()]);

            return [];
        }

        return $this->candidates($payload['results'] ?? [], $query->series);
    }

    /**
     * @param mixed $results
     * @return list<ProviderCandidate>
     */
    private function candidates(mixed $results, string $wanted): array
    {
        if (!is_array($results)) {
            return [];
        }

        $candidates = [];

        foreach (array_slice($results, 0, self::MAX_RESULTS) as $result) {
            if (!is_array($result) || !isset($result['id'])) {
                continue;
            }

            $series = is_array($result['series'] ?? null) ? (string) ($result['series']['name'] ?? '') : '';
            if ($series === '') {
                continue;
            }

            // Deliberately no publisher or summary: Metron's issue *list* does
            // not carry them — verified against the live API, whose rows are
            // id, series, number, issue, cover_date, store_date, image,
            // cover_hash, modified. Reading them here produced nothing but a
            // field that was always null. They live on /issue/{id}/, which is a
            // request per candidate and belongs with picking one, not listing.
            $candidates[] = new ProviderCandidate(
                provider: $this->key(),
                externalId: (string) $result['id'],
                series: $series,
                issueNumber: isset($result['number']) ? (string) $result['number'] : null,
                title: isset($result['issue']) ? (string) $result['issue'] : null,
                volume: isset($result['series']['volume']) ? (int) $result['series']['volume'] : null,
                publishedAt: $this->date($result['cover_date'] ?? null),
                coverUrl: isset($result['image']) ? (string) $result['image'] : null,
            );
        }

        return $this->closestFirst($candidates, $wanted);
    }

    /**
     * Metron matches a series name loosely, so "The Boys" comes back with 165
     * results led by "Adventures of The Dover Boys". Whoever is choosing wants
     * the exact series first, then the ones that start with what they asked
     * for, then the rest in the order Metron gave them.
     *
     * @param list<ProviderCandidate> $candidates
     * @return list<ProviderCandidate>
     */
    private function closestFirst(array $candidates, string $wanted): array
    {
        $wanted = mb_strtolower(trim($wanted));

        $rank = static function (ProviderCandidate $candidate) use ($wanted): int {
            $series = mb_strtolower($candidate->series);

            return match (true) {
                $series === $wanted => 0,
                str_starts_with($series, $wanted) => 1,
                str_contains($series, $wanted) => 2,
                default => 3,
            };
        };

        // Stable, so equally-ranked candidates keep Metron's own ordering.
        $ordered = array_values($candidates);
        $decorated = [];
        foreach ($ordered as $position => $candidate) {
            $decorated[] = [$rank($candidate), $position, $candidate];
        }
        usort($decorated, static fn (array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

        return array_map(static fn (array $row): ProviderCandidate => $row[2], $decorated);
    }

    private function date(mixed $value): ?\DateTimeImmutable
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        return \DateTimeImmutable::createFromFormat('!Y-m-d', $value) ?: null;
    }
}
