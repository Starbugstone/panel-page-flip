<?php

declare(strict_types=1);

namespace App\Metadata\Provider;

use App\Enum\ProviderStatus;
use App\Metadata\Classification;

/**
 * Comic Vine. Authenticates with an API key in the query string, and requires a
 * descriptive user agent — requests without one are refused.
 *
 * Comic Vine's published API terms are non-commercial use only. Whether this
 * server may spend its *own* key is an operator decision, held by
 * MetadataAccessResolver; a user's own key is theirs and bypasses that gate.
 *
 * The field mapping here is built from the documentation. Metron's list
 * endpoint turned out to differ from its own documentation in exactly this way,
 * so treat this as unconfirmed until somebody runs a real search — see
 * docs/metadata-enrichment.md.
 *
 * @see https://comicvine.gamespot.com/api/documentation
 */
final class ComicVineProvider extends HttpMetadataProvider
{
    private const BASE_URL = 'https://comicvine.gamespot.com/api';
    private const USER_AGENT = 'PanelPageFlip/1.0 (self-hosted comic library)';

    /** Comic Vine namespaces its ids by resource type; 4000 is an issue. */
    private const ISSUE_PREFIX = '4000-';

    public function key(): string
    {
        return 'comicvine';
    }

    public function label(): string
    {
        return 'Comic Vine';
    }

    public function verify(?string $secret): ProviderVerification
    {
        if ($secret === null || trim($secret) === '') {
            return ProviderVerification::unconfigured('Comic Vine needs an API key.');
        }

        try {
            $response = $this->httpClient->request('GET', self::BASE_URL.'/issues/', [
                'query' => ['api_key' => $secret, 'format' => 'json', 'limit' => 1],
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
            // The class only. Verification builds the same credential-bearing
            // request as a search, so the exception message is the same hazard.
            $this->logger?->info('Comic Vine verification could not reach the service.', ['exception' => $exception::class]);

            return ProviderVerification::unreachable('Comic Vine could not be reached from this server.');
        }
    }

    protected function performSearch(ProviderQuery $query, ProviderAccess $access): ProviderSearchResult
    {
        $terms = trim($query->series.' '.($query->issueNumber ?? ''));

        [$failure, $payload] = $this->call(self::BASE_URL.'/search/', [
            'query' => [
                // Deliberately never logged: the key is a secret and the URL
                // carries it.
                'api_key' => $access->secret(),
                'format' => 'json',
                'resources' => 'issue',
                'limit' => self::MAX_RESULTS,
                'query' => $terms,
            ],
            'headers' => ['User-Agent' => self::USER_AGENT],
        ], $access);

        if ($failure !== null) {
            return $failure;
        }

        $error = $this->errorIn($payload, $access);
        if ($error !== null) {
            return $error;
        }

        return ProviderSearchResult::found($this->key(), $this->candidates($payload['results'] ?? []), $access->origin);
    }

    protected function performDetail(string $externalId, ProviderAccess $access): ProviderSearchResult
    {
        [$failure, $payload] = $this->call(self::BASE_URL.'/issue/'.self::ISSUE_PREFIX.$externalId.'/', [
            'query' => ['api_key' => $access->secret(), 'format' => 'json'],
            'headers' => ['User-Agent' => self::USER_AGENT],
        ], $access);

        if ($failure !== null) {
            return $failure;
        }

        $error = $this->errorIn($payload, $access);
        if ($error !== null) {
            return $error;
        }

        $candidate = $this->detailedCandidate($payload['results'] ?? []);
        if ($candidate === null) {
            return $this->unavailable(ProviderStatus::Failed, 'Comic Vine no longer has that record.', $access);
        }

        return ProviderSearchResult::found($this->key(), [$candidate], $access->origin);
    }

    /**
     * Comic Vine reports its own errors inside a 200 response, so a body that
     * arrived intact still has to be asked whether it worked.
     *
     * @param array<string, mixed> $payload
     */
    private function errorIn(array $payload, ProviderAccess $access): ?ProviderSearchResult
    {
        $code = (int) ($payload['status_code'] ?? 0);
        if ($code === 1) {
            return null;
        }

        $this->logger?->info('Comic Vine returned an error.', ['status_code' => $code]);

        return match ($code) {
            100, 102 => $this->unavailable(ProviderStatus::Unauthorized, 'Comic Vine rejected the API key.', $access),
            107 => $this->unavailable(ProviderStatus::RateLimited, 'Comic Vine is rate limiting this server. Try again shortly.', $access),
            default => $this->unavailable(ProviderStatus::Failed, 'Comic Vine could not answer that request.', $access),
        };
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

            $series = is_array($result['volume'] ?? null) ? (string) ($result['volume']['name'] ?? '') : '';
            if ($series === '') {
                continue;
            }

            $candidates[] = new ProviderCandidate(
                provider: $this->key(),
                externalId: (string) $result['id'],
                series: $series,
                issueNumber: isset($result['issue_number']) ? (string) $result['issue_number'] : null,
                title: isset($result['name']) && is_string($result['name']) ? $result['name'] : null,
                summary: isset($result['deck']) && is_string($result['deck']) ? $result['deck'] : null,
                publishedAt: $this->date($result['cover_date'] ?? null),
                coverUrl: isset($result['image']['original_url']) ? (string) $result['image']['original_url'] : null,
            );
        }

        return $candidates;
    }

    private function detailedCandidate(mixed $result): ?ProviderCandidate
    {
        if (!is_array($result) || !isset($result['id'])) {
            return null;
        }

        $series = is_array($result['volume'] ?? null) ? (string) ($result['volume']['name'] ?? '') : '';
        if ($series === '') {
            return null;
        }

        return new ProviderCandidate(
            provider: $this->key(),
            externalId: (string) $result['id'],
            series: $series,
            issueNumber: isset($result['issue_number']) ? (string) $result['issue_number'] : null,
            title: isset($result['name']) && is_string($result['name']) ? $result['name'] : null,
            publisher: is_array($result['volume']['publisher'] ?? null)
                ? (string) ($result['volume']['publisher']['name'] ?? '') ?: null
                : null,
            summary: $this->summary($result),
            publishedAt: $this->date($result['cover_date'] ?? null),
            creators: $this->credits($result['person_credits'] ?? []),
            coverUrl: isset($result['image']['original_url']) ? (string) $result['image']['original_url'] : null,
            classification: new Classification(
                characters: Classification::clean($this->names($result['character_credits'] ?? [])),
                teams: Classification::clean($this->names($result['team_credits'] ?? [])),
                locations: Classification::clean($this->names($result['location_credits'] ?? [])),
                storyArcs: Classification::clean($this->names($result['story_arc_credits'] ?? [])),
            ),
            isDetailed: true,
        );
    }

    /**
     * `description` is HTML and `deck` is a plain-text blurb. The blurb is what
     * a description field wants; the HTML would arrive in the edit form as
     * markup for the user to clean up by hand.
     *
     * @param array<string, mixed> $result
     */
    private function summary(array $result): ?string
    {
        foreach (['deck', 'description'] as $field) {
            $value = $result[$field] ?? null;
            if (!is_string($value) || trim($value) === '') {
                continue;
            }

            $text = trim(html_entity_decode(strip_tags($value), \ENT_QUOTES | \ENT_HTML5, 'UTF-8'));
            if ($text !== '') {
                return $text;
            }
        }

        return null;
    }

    /**
     * Comic Vine reports credits as `{ name, role: "writer, artist" }` — one
     * row per person with the roles comma-separated inside a string.
     *
     * @return array<string, list<string>>
     */
    private function credits(mixed $credits): array
    {
        $pairs = [];

        foreach (is_array($credits) ? $credits : [] as $credit) {
            if (!is_array($credit) || !isset($credit['name']) || !is_string($credit['name'])) {
                continue;
            }

            $roles = is_string($credit['role'] ?? null) ? explode(',', $credit['role']) : ['other'];
            foreach ($roles as $role) {
                $pairs[] = [$role, $credit['name']];
            }
        }

        return $this->foldCredits($pairs);
    }
}
