<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use App\Enum\ProviderStatus;
use App\Metadata\Provider\PersonalProviderCredentials;
use App\Metadata\Provider\ProviderAccess;
use App\Metadata\Provider\SharedProviderCredentials;

/**
 * Whose credential a lookup would spend, and whether it may.
 *
 * Every outbound provider call goes through here. The decision has several
 * independent veto points and they are deliberately kept in one place: spread
 * across the call sites, a new one would eventually be written without all of
 * them, and the failure would be silent overspending of somebody else's quota.
 *
 * For the installation's shared Metron account, access is the conjunction of
 * all of these, in this order:
 *
 *     environment allows it
 *     AND an administrator enabled it
 *     AND a shared token is configured
 *     AND the circuit breaker is not holding it off
 *
 * The environment flag is checked first and fails closed. An operator who has
 * set METRON_SHARED_ENABLED=false must not be able to be overruled from inside
 * the application, which is the whole point of putting a switch out there.
 */
final class MetadataAccessResolver
{
    public function __construct(
        private readonly SharedProviderCredentials $configuration,
        private readonly PersonalProviderCredentials $personalCredentials,
        private readonly ProviderCircuitBreaker $circuitBreaker,
        private readonly bool $metronSharedAllowedByEnvironment,
        private readonly bool $comicVineSharedAllowedByEnvironment,
    ) {
    }

    public function resolve(string $provider, User $user): ProviderAccess
    {
        // Local sources are unaffected by this: ComicInfo.xml and the filename
        // parser never leave the server, and a user with external lookups
        // withdrawn keeps both.
        if (!$user->isMetadataApiEnabled()) {
            return ProviderAccess::denied(
                $provider,
                ProviderStatus::Forbidden,
                'External metadata lookups are turned off for this account.'
            );
        }

        $access = match ($provider) {
            'metron' => $this->resolveMetron($user),
            'comicvine' => $this->resolveComicVine($user),
            default => ProviderAccess::denied($provider, ProviderStatus::Unconfigured, 'Unknown metadata provider.'),
        };

        return $access->isGranted() ? $this->unlessPaused($access) : $access;
    }

    private function resolveMetron(User $user): ProviderAccess
    {
        $personal = $this->personalCredentials->metronToken($user);
        if ($personal !== null) {
            return ProviderAccess::granted('metron', 'personal', $personal);
        }

        if (!$this->metronSharedAllowedByEnvironment) {
            return ProviderAccess::denied(
                'metron',
                ProviderStatus::Disabled,
                'Shared Metron access is turned off for this server. A personal Metron token still works.'
            );
        }

        if (!$this->configuration->isMetronSharedEnabled()) {
            return ProviderAccess::denied(
                'metron',
                ProviderStatus::Disabled,
                'An administrator has turned off shared Metron access. A personal Metron token still works.'
            );
        }

        $shared = $this->configuration->metronToken();
        if ($shared === null) {
            return ProviderAccess::denied(
                'metron',
                ProviderStatus::Unconfigured,
                'No Metron token is configured. Add a personal one in your settings, or ask an administrator.'
            );
        }

        return ProviderAccess::granted('metron', 'shared', $shared);
    }

    /**
     * Comic Vine's published terms are non-commercial use only, and a user
     * supplying their own key does not waive them. So a personal key is still
     * gated on the installation having declared Comic Vine usable at all —
     * bring-your-own-key is not a way around the provider's terms, and the
     * environment flag is where an operator records that it is entitled to use
     * the service.
     */
    private function resolveComicVine(User $user): ProviderAccess
    {
        if (!$this->comicVineSharedAllowedByEnvironment) {
            return ProviderAccess::denied(
                'comicvine',
                ProviderStatus::Disabled,
                'Comic Vine is turned off for this server.'
            );
        }

        if (!$this->configuration->isComicVineEnabled()) {
            return ProviderAccess::denied(
                'comicvine',
                ProviderStatus::Disabled,
                'An administrator has turned off Comic Vine.'
            );
        }

        $personal = $this->personalCredentials->comicVineApiKey($user);
        if ($personal !== null) {
            return ProviderAccess::granted('comicvine', 'personal', $personal);
        }

        $shared = $this->configuration->comicVineApiKey();
        if ($shared === null) {
            return ProviderAccess::denied(
                'comicvine',
                ProviderStatus::Unconfigured,
                'No Comic Vine API key is configured.'
            );
        }

        return ProviderAccess::granted('comicvine', 'shared', $shared);
    }

    /**
     * A pause is temporary and belongs to the account, not to the switch. It is
     * checked after the credential resolves because it is that credential's
     * upstream account that was throttled — a user with their own token is not
     * held off because the shared one was.
     */
    private function unlessPaused(ProviderAccess $access): ProviderAccess
    {
        $seconds = $this->circuitBreaker->pausedFor($access->accountKey());
        if ($seconds === null) {
            return $access;
        }

        return ProviderAccess::denied($access->provider, ProviderStatus::Paused, sprintf(
            'Lookups are paused for about %d minute%s after the provider asked this server to slow down.',
            $minutes = max(1, (int) ceil($seconds / 60)),
            $minutes === 1 ? '' : 's'
        ));
    }
}
