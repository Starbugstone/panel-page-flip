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
 * A user's own token is tried first and answers on its own. It spends their
 * allowance rather than anybody else's, so none of the switches governing the
 * shared account have anything to say about it — those exist to control who may
 * spend the installation's credential, and a personal token is not one.
 *
 * Failing that, the shared account is the conjunction of all of these, in order:
 *
 *     environment allows it
 *     AND an administrator enabled it
 *     AND a shared credential is configured
 *     AND the circuit breaker is not holding it off
 *
 * The environment flag is checked first. An operator who has set
 * METRON_SHARED_ENABLED=false must not be able to be overruled from inside the
 * application, which is the whole point of putting a switch out there.
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
        $personal = $this->personalToken('metron', $user);
        if ($personal !== null) {
            return ProviderAccess::granted('metron', 'personal', $personal);
        }

        if (!$this->metronSharedAllowedByEnvironment) {
            return ProviderAccess::denied(
                'metron',
                ProviderStatus::Disabled,
                'Shared Metron access is turned off for this server. Your own Metron token would still work.'
            );
        }

        if (!$this->configuration->isMetronSharedEnabled()) {
            return ProviderAccess::denied(
                'metron',
                ProviderStatus::Disabled,
                'An administrator has turned off shared Metron access. Your own Metron token would still work.'
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
     * The same shape as Metron, deliberately: a personal key answers on its own,
     * and the switches below govern the installation's shared key.
     *
     * Comic Vine's published terms are non-commercial use only. Somebody using
     * their own key against their own library is the party those terms bind, and
     * they have accepted them by obtaining the key — so the operator's switch
     * stops the operator's key, not theirs.
     */
    private function resolveComicVine(User $user): ProviderAccess
    {
        $personal = $this->personalToken('comicvine', $user);
        if ($personal !== null) {
            return ProviderAccess::granted('comicvine', 'personal', $personal);
        }

        if (!$this->comicVineSharedAllowedByEnvironment) {
            return ProviderAccess::denied(
                'comicvine',
                ProviderStatus::Disabled,
                'Comic Vine is turned off for this server. Your own Comic Vine key would still work.'
            );
        }

        if (!$this->configuration->isComicVineEnabled()) {
            return ProviderAccess::denied(
                'comicvine',
                ProviderStatus::Disabled,
                'An administrator has turned off Comic Vine. Your own Comic Vine key would still work.'
            );
        }

        $shared = $this->configuration->comicVineApiKey();
        if ($shared === null) {
            return ProviderAccess::denied(
                'comicvine',
                ProviderStatus::Unconfigured,
                'No Comic Vine API key is configured. Add a personal one in your settings, or ask an administrator.'
            );
        }

        return ProviderAccess::granted('comicvine', 'shared', $shared);
    }

    /**
     * A user's own token, or null when there is none to use.
     *
     * Null rather than a denial when an administrator has switched personal
     * tokens off: the point of that switch is that the installation falls back
     * to its own credential, not that the lookup stops. The token stays stored
     * and starts working again if the switch comes back on.
     */
    private function personalToken(string $provider, User $user): ?string
    {
        if (!$this->configuration->arePersonalCredentialsEnabled()) {
            return null;
        }

        return match ($provider) {
            'metron' => $this->personalCredentials->metronToken($user),
            'comicvine' => $this->personalCredentials->comicVineApiKey($user),
            default => null,
        };
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
