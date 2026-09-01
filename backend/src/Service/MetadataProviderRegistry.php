<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use App\Enum\ProviderStatus;
use App\Metadata\Provider\MetadataProviderInterface;
use App\Metadata\Provider\ProviderAccess;
use App\Metadata\Provider\ProviderLookup;
use App\Metadata\Provider\PublicProviderStatus;
use App\Metadata\Provider\ProviderQuery;
use App\Metadata\Provider\ProviderSearchResult;
use App\Metadata\Provider\ProviderVerification;

/**
 * One provider per lookup, chosen deliberately.
 *
 * The earlier version asked every configured provider at once. That spends two
 * accounts' allowance on one click, and when the first provider fails, falling
 * through to the second quietly spends somebody else's quota to paper over it.
 * So: a preference order, one request, and the other providers reported as
 * unasked rather than silently drained.
 *
 * A user's own credential wins the preference, because a lookup that spends
 * their own allowance costs the installation nothing and cannot be exhausted by
 * anybody else.
 */
final class MetadataProviderRegistry
{
    /** @param iterable<MetadataProviderInterface> $providers */
    public function __construct(
        private readonly iterable $providers,
        private readonly MetadataAccessResolver $access,
        private readonly CandidateRanker $ranker,
        private readonly ProviderQuotaTracker $quota,
    ) {
    }

    /** @return list<MetadataProviderInterface> */
    public function all(): array
    {
        return array_values(iterator_to_array($this->providers, false));
    }

    /** @return list<string> */
    public function keys(): array
    {
        return array_map(
            static fn (MetadataProviderInterface $provider): string => $provider->key(),
            $this->all()
        );
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
     * Whether each provider would answer this user.
     *
     * Reduced to what a normal user is entitled to know — see
     * PublicProviderStatus. Never the credential, never which account would be
     * spent, and never the shared account's configuration state.
     *
     * @return list<PublicProviderStatus>
     */
    public function statusFor(User $user): array
    {
        return array_map(
            fn (MetadataProviderInterface $provider): PublicProviderStatus => PublicProviderStatus::fromAccess(
                $provider->key(),
                $provider->label(),
                $this->access->resolve($provider->key(), $user)
            ),
            $this->all()
        );
    }

    /**
     * One lookup's outcome, in the same reduced terms.
     *
     * The registry is asked for this rather than the controller building it,
     * because the label lives here and the reduction has to happen in exactly
     * one place — a second copy is where the two would drift and the internal
     * message would reach a user.
     */
    public function publicResult(ProviderSearchResult $result): PublicProviderStatus
    {
        $provider = $this->get($result->provider);

        return PublicProviderStatus::fromResult(
            $result->provider,
            $provider?->label() ?? $result->provider,
            $result
        );
    }

    /**
     * @param list<ProviderSearchResult> $results
     * @return list<PublicProviderStatus>
     */
    public function publicResults(array $results): array
    {
        return array_map(
            fn (ProviderSearchResult $result): PublicProviderStatus => $this->publicResult($result),
            $results
        );
    }

    /**
     * The administrator's view: configuration and observed quota, for every
     * provider, independent of any one user's permission.
     *
     * @param array<string, string|null> $sharedSecrets provider key => the installation's secret
     * @return list<array<string, mixed>>
     */
    public function adminStatus(array $sharedSecrets, MetadataProviderConfigurationService $configuration): array
    {
        return array_map(function (MetadataProviderInterface $provider) use ($sharedSecrets, $configuration): array {
            $secret = $sharedSecrets[$provider->key()] ?? null;

            return [
                'key' => $provider->key(),
                'label' => $provider->label(),
                'configured' => $secret !== null,
                'enabled' => match ($provider->key()) {
                    'metron' => $configuration->isMetronSharedEnabled(),
                    'comicvine' => $configuration->isComicVineEnabled(),
                    default => false,
                },
                // Keyed by a hash of the secret, so quota can be shown without
                // the secret being anywhere near this response.
                'quota' => $secret === null
                    ? []
                    : $this->quota->state($provider->key().'.'.hash('xxh128', $secret)),
            ];
        }, $this->all());
    }

    /**
     * Test secrets against the live services and report what each said.
     *
     * @param array<string, string|null> $secretsByProvider
     * @return list<array{key: string, label: string, status: string, message: string}>
     */
    public function verify(array $secretsByProvider): array
    {
        return array_map(
            static function (MetadataProviderInterface $provider) use ($secretsByProvider): array {
                $result = $provider->verify($secretsByProvider[$provider->key()] ?? null);

                return [
                    'key' => $provider->key(),
                    'label' => $provider->label(),
                    'status' => $result->status->value,
                    'message' => $result->message,
                ];
            },
            $this->all()
        );
    }

    public function verifyOne(string $providerKey, ?string $secret): ?ProviderVerification
    {
        return $this->get($providerKey)?->verify($secret);
    }

    /**
     * @param string|null $only a provider the user explicitly chose, rather than the preferred one
     */
    public function search(ProviderQuery $query, User $user, ?string $only = null): ProviderLookup
    {
        $accessByProvider = [];
        foreach ($this->all() as $provider) {
            $accessByProvider[$provider->key()] = $this->access->resolve($provider->key(), $user);
        }

        $chosen = $only ?? $this->preferred($accessByProvider);
        if ($chosen === null || $this->get($chosen) === null) {
            return ProviderLookup::nothingToAsk($this->unaskedResults($accessByProvider));
        }

        $access = $accessByProvider[$chosen];
        if (!$access->isGranted()) {
            return ProviderLookup::nothingToAsk($this->unaskedResults($accessByProvider));
        }

        $result = $this->get($chosen)->search($query, $access);

        return new ProviderLookup(
            $this->ranker->rank($result->candidates, $query),
            $this->resultsWith($accessByProvider, $result),
            $chosen
        );
    }

    /** One exact record, for refreshing a comic that was matched before. */
    public function detail(string $providerKey, string $externalId, User $user): ProviderSearchResult
    {
        $provider = $this->get($providerKey);
        if ($provider === null) {
            return ProviderSearchResult::unavailable(
                $providerKey,
                ProviderStatus::Unconfigured,
                'That provider is not available on this server.'
            );
        }

        return $provider->detail($externalId, $this->access->resolve($providerKey, $user));
    }

    /**
     * The provider to ask when the user has not picked one.
     *
     * A personal credential first, then anything else that is allowed, in the
     * order the providers are registered. Deliberately picks one and stops:
     * there is no cascade, so a provider that answers badly does not cause a
     * second account to be charged for the same question.
     *
     * @param array<string, ProviderAccess> $accessByProvider
     */
    private function preferred(array $accessByProvider): ?string
    {
        foreach (['personal', 'shared'] as $origin) {
            foreach ($this->all() as $provider) {
                $access = $accessByProvider[$provider->key()] ?? null;
                if ($access !== null && $access->isGranted() && $access->origin === $origin) {
                    return $provider->key();
                }
            }
        }

        return null;
    }

    /**
     * @param array<string, ProviderAccess> $accessByProvider
     * @return list<ProviderSearchResult>
     */
    private function resultsWith(array $accessByProvider, ProviderSearchResult $searched): array
    {
        return array_map(
            fn (MetadataProviderInterface $provider): ProviderSearchResult => $provider->key() === $searched->provider
                ? $searched
                : $this->notAsked($accessByProvider[$provider->key()]),
            $this->all()
        );
    }

    /**
     * @param array<string, ProviderAccess> $accessByProvider
     * @return list<ProviderSearchResult>
     */
    private function unaskedResults(array $accessByProvider): array
    {
        return array_map(
            fn (MetadataProviderInterface $provider): ProviderSearchResult => $this->notAsked($accessByProvider[$provider->key()]),
            $this->all()
        );
    }

    private function notAsked(ProviderAccess $access): ProviderSearchResult
    {
        if (!$access->isGranted()) {
            return ProviderSearchResult::fromDeniedAccess($access);
        }

        return ProviderSearchResult::unavailable(
            $access->provider,
            ProviderStatus::Ok,
            'Not asked. Choose this provider to search it instead.',
            $access->origin
        );
    }
}
