<?php

declare(strict_types=1);

namespace App\Metadata\Provider;

use App\Enum\ProviderStatus;
use App\Metadata\Classification;

/**
 * Metron, a community comic database.
 *
 * Authenticates with a revocable bearer token. Metron added token
 * authentication in July 2026 and recommends it for integrations; Basic Auth
 * against a real account password still works upstream but is on its way out,
 * and asking a user for their account password to read a public database was
 * never a reasonable trade.
 *
 * @see https://metron.cloud/
 * @see https://metron-project.github.io/blog/token-authentication
 */
final class MetronProvider extends HttpMetadataProvider
{
    private const BASE_URL = 'https://metron.cloud/api';

    public function key(): string
    {
        return 'metron';
    }

    public function label(): string
    {
        return 'Metron';
    }

    public function verify(?string $secret): ProviderVerification
    {
        if ($secret === null || trim($secret) === '') {
            return ProviderVerification::unconfigured('Metron needs an API token.');
        }

        try {
            // The cheapest authenticated call there is: one page of a list that
            // always exists. Enough to prove the token, and small enough to be
            // polite about asking.
            $response = $this->httpClient->request('GET', self::BASE_URL.'/series/', [
                'headers' => $this->authorisation($secret),
                'query' => ['page' => 1],
                'timeout' => self::TIMEOUT_SECONDS,
            ]);

            return match (true) {
                $response->getStatusCode() === 200 => ProviderVerification::ok('Metron accepted the token.'),
                in_array($response->getStatusCode(), [401, 403], true) => ProviderVerification::unauthorized(
                    'Metron refused the token.'
                ),
                $response->getStatusCode() === 429 => ProviderVerification::rateLimited(
                    'Metron is rate limiting this server. The token may still be fine; try again shortly.'
                ),
                default => ProviderVerification::failed(
                    sprintf('Metron answered with HTTP %d.', $response->getStatusCode())
                ),
            };
        } catch (\Throwable $exception) {
            // The class only. Verification builds the same credential-bearing
            // request as a search, so the exception message is the same hazard.
            $this->logger?->info('Metron verification could not reach the service.', ['exception' => $exception::class]);

            return ProviderVerification::unreachable('Metron could not be reached from this server.');
        }
    }

    protected function performSearch(ProviderQuery $query, ProviderAccess $access): ProviderSearchResult
    {
        $parameters = ['series_name' => $query->series];
        if ($query->issueNumber !== null) $parameters['number'] = $query->issueNumber;
        if ($query->year !== null) $parameters['cover_year'] = $query->year;

        [$failure, $payload] = $this->call(self::BASE_URL.'/issue/', [
            'headers' => $this->authorisation($access->secret()),
            'query' => $parameters,
        ], $access);

        if ($failure !== null) {
            return $failure;
        }

        return ProviderSearchResult::found($this->key(), $this->candidates($payload['results'] ?? []), $access->origin);
    }

    /**
     * The issue *list* carries id, series, number, issue, cover_date,
     * store_date, image, cover_hash and modified — verified against the live
     * API, and notably not the publisher, description or classification. Those
     * live here, one request per record, which is why detail is fetched when a
     * candidate is chosen rather than for every row of a search.
     */
    protected function performDetail(string $externalId, ProviderAccess $access): ProviderSearchResult
    {
        [$failure, $payload] = $this->call(self::BASE_URL.'/issue/'.$externalId.'/', [
            'headers' => $this->authorisation($access->secret()),
        ], $access);

        if ($failure !== null) {
            return $failure;
        }

        $candidate = $this->detailedCandidate($payload);
        if ($candidate === null) {
            return $this->unavailable(ProviderStatus::Failed, 'Metron no longer has that record.', $access);
        }

        return ProviderSearchResult::found($this->key(), [$candidate], $access->origin);
    }

    /**
     * Django REST framework's token scheme, which is what Metron is built on.
     *
     * @return array<string, string>
     */
    private function authorisation(string $token): array
    {
        return ['Authorization' => 'Token '.$token];
    }

    /** @return list<ProviderCandidate> */
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

            $series = is_array($result['series'] ?? null) ? (string) ($result['series']['name'] ?? '') : '';
            if ($series === '') {
                continue;
            }

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

        return $candidates;
    }

    private function detailedCandidate(mixed $result): ?ProviderCandidate
    {
        if (!is_array($result) || !isset($result['id'])) {
            return null;
        }

        $series = is_array($result['series'] ?? null) ? (string) ($result['series']['name'] ?? '') : '';
        if ($series === '') {
            return null;
        }

        $publisher = is_array($result['publisher'] ?? null) ? ($result['publisher']['name'] ?? null) : null;

        return new ProviderCandidate(
            provider: $this->key(),
            externalId: (string) $result['id'],
            series: $series,
            issueNumber: isset($result['number']) ? (string) $result['number'] : null,
            title: isset($result['name']) && is_string($result['name']) ? $result['name'] : (isset($result['title']) && is_string($result['title']) ? $result['title'] : null),
            volume: isset($result['series']['volume']) ? (int) $result['series']['volume'] : null,
            issueCount: isset($result['series']['issue_count']) ? (int) $result['series']['issue_count'] : null,
            publisher: is_string($publisher) ? $publisher : null,
            summary: isset($result['desc']) && is_string($result['desc']) ? $result['desc'] : null,
            publishedAt: $this->date($result['cover_date'] ?? null),
            creators: $this->credits($result['credits'] ?? []),
            coverUrl: isset($result['image']) ? (string) $result['image'] : null,
            // Informational only. Nothing derived from this ever touches the
            // explicit-content flag, which stays the owner's declaration.
            ageRating: is_array($result['rating'] ?? null) && is_string($result['rating']['name'] ?? null)
                ? $result['rating']['name']
                : null,
            classification: new Classification(
                genres: Classification::clean($this->names($result['series']['genres'] ?? [])),
                characters: Classification::clean($this->names($result['characters'] ?? [])),
                teams: Classification::clean($this->names($result['teams'] ?? [])),
                storyArcs: Classification::clean($this->names($result['arcs'] ?? [])),
            ),
            isDetailed: true,
        );
    }

    /**
     * Metron reports credits as `{ creator, role: [{ name }] }`. The stored
     * shape is role => names, matching what ComicInfo.xml produces, so both
     * sources reach the review UI as the same thing.
     *
     * @return array<string, list<string>>
     */
    private function credits(mixed $credits): array
    {
        if (!is_array($credits)) {
            return [];
        }

        $byRole = [];

        foreach ($credits as $credit) {
            if (!is_array($credit) || !isset($credit['creator']) || !is_string($credit['creator'])) {
                continue;
            }

            foreach ($this->names($credit['role'] ?? []) ?: ['Other'] as $role) {
                $key = mb_strtolower(trim($role));
                if ($key === '') {
                    continue;
                }

                $byRole[$key] ??= [];
                if (!in_array($credit['creator'], $byRole[$key], true)) {
                    $byRole[$key][] = $credit['creator'];
                }
            }
        }

        return $byRole;
    }
}
