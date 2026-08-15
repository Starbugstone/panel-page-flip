<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Comic;
use App\Metadata\Provider\MetadataProviderInterface;
use App\Metadata\Provider\ProviderCredentials;
use App\Metadata\Provider\ProviderQuery;

/**
 * Every configured provider, asked at once.
 *
 * A provider that is unconfigured, unreachable or rate-limited contributes
 * nothing rather than failing the request, so a lookup degrades to fewer
 * candidates instead of to an error.
 */
final class MetadataProviderRegistry
{
    /** @param iterable<MetadataProviderInterface> $providers */
    public function __construct(private readonly iterable $providers)
    {
    }

    /** @return list<MetadataProviderInterface> */
    public function all(): array
    {
        return array_values(iterator_to_array($this->providers, false));
    }

    public function get(string $key): ?MetadataProviderInterface
    {
        foreach ($this->providers as $provider) {
            if ($provider->key() === $key) {
                return $provider;
            }
        }

        return null;
    }

    /**
     * @return list<array{key: string, label: string, configured: bool}>
     */
    public function status(): array
    {
        return array_map(
            static fn (MetadataProviderInterface $provider): array => [
                'key' => $provider->key(),
                'label' => $provider->label(),
                'configured' => $provider->isConfigured(),
            ],
            $this->all()
        );
    }

    /**
     * Test each provider against the given credentials.
     *
     * @return list<array{key: string, label: string, status: string, message: string}>
     */
    public function verify(ProviderCredentials $candidate): array
    {
        return array_map(
            static function (MetadataProviderInterface $provider) use ($candidate): array {
                $result = $provider->verify($candidate);

                return [
                    'key' => $provider->key(),
                    'label' => $provider->label(),
                    'status' => $result->status,
                    'message' => $result->message,
                ];
            },
            $this->all()
        );
    }

    /** @return list<\App\Metadata\Provider\ProviderCandidate> */
    public function search(Comic $comic, ?string $only = null): array
    {
        $query = ProviderQuery::fromComic($comic);
        if ($query === null) {
            return [];
        }

        $candidates = [];

        foreach ($this->all() as $provider) {
            if ($only !== null && $provider->key() !== $only) {
                continue;
            }

            foreach ($provider->search($query) as $candidate) {
                $candidates[] = $candidate;
            }
        }

        return $candidates;
    }
}
