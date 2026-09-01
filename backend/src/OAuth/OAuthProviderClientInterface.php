<?php

declare(strict_types=1);

namespace App\OAuth;

use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;

interface OAuthProviderClientInterface
{
    /** @return list<string> */
    public function providers(): array;

    /** @return array<string, bool> */
    public function availability(): array;

    public function isKnown(string $provider): bool;

    public function isEnabled(string $provider): bool;

    public function authorizationRedirect(string $provider, bool $forceReauthentication = false): RedirectResponse;

    /**
     * Validate and consume the callback state before exchanging the code, then
     * return only the provider profile fields the application needs.
     */
    public function fetchProfile(string $provider, Request $request): OAuthProfile;
}
