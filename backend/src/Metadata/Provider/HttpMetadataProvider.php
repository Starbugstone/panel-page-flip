<?php

declare(strict_types=1);

namespace App\Metadata\Provider;

use App\Enum\ProviderStatus;
use App\Service\ProviderCircuitBreaker;
use App\Service\ProviderQuotaTracker;
use Psr\Log\LoggerInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The parts of talking to a metadata provider that are the same whichever one
 * it is: caching, the local abuse ceiling, reading quota headers, and turning a
 * transport failure into a result rather than an exception.
 *
 * The subclasses are left with the one thing that genuinely differs — the shape
 * of the requests and of the JSON that comes back.
 */
abstract class HttpMetadataProvider implements MetadataProviderInterface
{
    protected const TIMEOUT_SECONDS = 10;
    protected const MAX_RESULTS = 10;

    private const SEARCH_TTL_SECONDS = 86_400;

    /**
     * Detail is kept far longer than a search. It is keyed by an exact record
     * id rather than by a fuzzy query, so it cannot go stale in the way a
     * search can, and it is what makes "refresh metadata" affordable.
     */
    private const DETAIL_TTL_SECONDS = 604_800;

    public function __construct(
        protected readonly HttpClientInterface $httpClient,
        protected readonly RateLimiterFactory $metadataProviderLimiter,
        protected readonly CacheInterface $cache,
        protected readonly ProviderQuotaTracker $quota,
        protected readonly ProviderCircuitBreaker $circuitBreaker,
        protected readonly ?LoggerInterface $logger = null,
    ) {
    }

    public function search(ProviderQuery $query, ProviderAccess $access): ProviderSearchResult
    {
        if (!$access->isGranted()) {
            return ProviderSearchResult::fromDeniedAccess($access);
        }

        return $this->remember(
            $query->cacheKey($this->key()),
            self::SEARCH_TTL_SECONDS,
            fn (): ProviderSearchResult => $this->performSearch($query, $access),
            $access
        );
    }

    public function detail(string $externalId, ProviderAccess $access): ProviderSearchResult
    {
        if (!$access->isGranted()) {
            return ProviderSearchResult::fromDeniedAccess($access);
        }

        $externalId = trim($externalId);
        if ($externalId === '' || !preg_match('/^[A-Za-z0-9\-_]{1,64}$/', $externalId)) {
            // The id reaches a URL path, so it is checked against a shape
            // rather than trusted because it came from our own JSON earlier.
            return ProviderSearchResult::unavailable(
                $this->key(),
                ProviderStatus::Failed,
                'That record reference is not one this provider could have issued.'
            );
        }

        return $this->remember(
            $this->key().'.detail.'.$externalId,
            self::DETAIL_TTL_SECONDS,
            fn (): ProviderSearchResult => $this->performDetail($externalId, $access),
            $access
        );
    }

    abstract protected function performSearch(ProviderQuery $query, ProviderAccess $access): ProviderSearchResult;

    abstract protected function performDetail(string $externalId, ProviderAccess $access): ProviderSearchResult;

    /**
     * Cache a result, but only one worth keeping.
     *
     * A provider that was unreachable or throttled has said nothing about this
     * comic. Storing that silence for a day would turn a thirty-second outage
     * into a comic that permanently "has no match", which is the quiet kind of
     * wrong this whole feature is trying to avoid.
     */
    private function remember(
        string $key,
        int $ttlSeconds,
        callable $produce,
        ProviderAccess $access
    ): ProviderSearchResult {
        $result = $this->cache->get(
            $key,
            function (ItemInterface $item, bool &$save) use ($ttlSeconds, $produce, $access): ProviderSearchResult {
                $item->expiresAfter($ttlSeconds);

                $ceiling = $this->metadataProviderLimiter->create($access->accountKey())->consume();
                if (!$ceiling->isAccepted()) {
                    $save = false;

                    return ProviderSearchResult::unavailable(
                        $this->key(),
                        ProviderStatus::RateLimited,
                        'This server has reached its own hourly limit for '.$this->label().'. Try again shortly.',
                        $access->origin
                    );
                }

                /** @var ProviderSearchResult $result */
                $result = $produce();
                $save = $result->isCacheable();

                return $result;
            }
        );

        // The candidates are shared; the provenance is not. A cache entry keeps
        // the origin of whoever populated it, so without this a user on their
        // own token would be told the shared one answered, and the reverse.
        return $result->withOrigin($access->origin);
    }

    /**
     * Send a request and classify the answer, recording what it implies about
     * the account's remaining allowance either way.
     *
     * @param array<string, mixed> $options
     * @return array{0: ProviderSearchResult|null, 1: array<string, mixed>} a failure result, or null and the decoded body
     */
    protected function call(string $url, array $options, ProviderAccess $access): array
    {
        try {
            $response = $this->httpClient->request('GET', $url, $options + ['timeout' => static::TIMEOUT_SECONDS]);
            $status = $response->getStatusCode();
            $this->quota->record($access->accountKey(), $response);
        } catch (\Throwable $exception) {
            // The exception class, and nothing out of the exception itself. A
            // transport failure routinely quotes the request URL, and Comic
            // Vine's URL carries `api_key=` — so logging the message is how the
            // installation's credential ends up in a log file that is shipped,
            // rotated and read by people who should never see it. The class
            // name is enough to tell a timeout from a DNS failure.
            $this->logger?->info($this->label().' could not be reached.', ['exception' => $exception::class]);
            $this->circuitBreaker->recordFailure($access->accountKey(), ProviderStatus::Unreachable);

            return [$this->unavailable(ProviderStatus::Unreachable, $this->label().' could not be reached from this server.', $access), []];
        }

        if (in_array($status, [401, 403], true)) {
            $this->circuitBreaker->recordFailure($access->accountKey(), ProviderStatus::Unauthorized);

            return [$this->unavailable(
                ProviderStatus::Unauthorized,
                $access->origin === 'personal'
                    ? $this->label().' refused your token. Check it in your settings.'
                    : $this->label().' refused this server\'s credentials.',
                $access
            ), []];
        }

        if ($status === 429) {
            $retryAfter = $this->quota->retryDelay($response);
            $this->circuitBreaker->recordFailure($access->accountKey(), ProviderStatus::RateLimited, $retryAfter);

            return [$this->unavailable(ProviderStatus::RateLimited, $this->label().' is rate limiting this server. Try again shortly.', $access), []];
        }

        if ($status !== 200) {
            $this->circuitBreaker->recordFailure($access->accountKey(), ProviderStatus::Failed);
            $this->logger?->info($this->label().' lookup failed.', ['status' => $status]);

            return [$this->unavailable(ProviderStatus::Failed, sprintf('%s answered with HTTP %d.', $this->label(), $status), $access), []];
        }

        try {
            $payload = $response->toArray(false);
        } catch (\Throwable) {
            $this->circuitBreaker->recordFailure($access->accountKey(), ProviderStatus::Failed);

            return [$this->unavailable(ProviderStatus::Failed, $this->label().' answered with something this server could not read.', $access), []];
        }

        $this->circuitBreaker->recordSuccess($access->accountKey());

        return [null, $payload];
    }

    protected function unavailable(ProviderStatus $status, string $message, ProviderAccess $access): ProviderSearchResult
    {
        return ProviderSearchResult::unavailable($this->key(), $status, $message, $access->origin);
    }

    protected function date(mixed $value): ?\DateTimeImmutable
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        return \DateTimeImmutable::createFromFormat('!Y-m-d', $value) ?: null;
    }

    /**
     * Bound and map the provider's result rows. Each provider owns the JSON
     * shape; the defensive list traversal and result ceiling live here once.
     *
     * @param callable(array<string, mixed>): ?ProviderCandidate $map
     * @return list<ProviderCandidate>
     */
    protected function mapCandidateRows(mixed $results, callable $map): array
    {
        if (!is_array($results)) {
            return [];
        }

        $candidates = [];
        foreach (array_slice($results, 0, self::MAX_RESULTS) as $result) {
            if (!is_array($result)) {
                continue;
            }

            /** @var array<string, mixed> $result */
            $candidate = $map($result);
            if ($candidate !== null) {
                $candidates[] = $candidate;
            }
        }

        return $candidates;
    }

    /**
     * Names out of a list of `{ name: ... }` objects, which is how every
     * provider here reports characters, teams and the rest.
     *
     * @return list<string>
     */
    protected function names(mixed $rows, string $field = 'name'): array
    {
        if (!is_array($rows)) {
            return [];
        }

        $names = [];
        foreach ($rows as $row) {
            if (is_string($row)) {
                $names[] = $row;
            } elseif (is_array($row) && isset($row[$field]) && is_string($row[$field])) {
                $names[] = $row[$field];
            }
        }

        return $names;
    }

    /**
     * Fold credit rows into role => people.
     *
     * The providers disagree on the shape of a row — Comic Vine names the
     * person in `name` and comma-separates their roles into one string, Metron
     * uses `creator` and lists roles as objects — so each reads its own shape
     * and hands the pairs here. What is the same either way lives once: roles
     * are keyed lower-cased, blank ones are dropped, and nobody is listed
     * twice under one role.
     *
     * @param list<array{0: string, 1: string}> $pairs role as the provider
     *                                                 spelled it, and the person
     * @return array<string, list<string>>
     */
    protected function foldCredits(array $pairs): array
    {
        $byRole = [];

        foreach ($pairs as [$role, $person]) {
            $key = mb_strtolower(trim($role));
            if ($key === '') {
                continue;
            }

            $byRole[$key] ??= [];
            if (!in_array($person, $byRole[$key], true)) {
                $byRole[$key][] = $person;
            }
        }

        return $byRole;
    }
}
