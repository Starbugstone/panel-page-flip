<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\OAuth\OAuthProfile;
use App\OAuth\OAuthProviderClientInterface;
use KnpU\OAuth2ClientBundle\Exception\InvalidStateException;
use KnpU\OAuth2ClientBundle\Exception\MissingAuthorizationCodeException;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

final class FakeOAuthProviderClient implements OAuthProviderClientInterface
{
    public const STATE = 'functional-test-oauth-state';
    private const STATE_KEY = 'functional-test.oauth.state';

    public static int $profileFetches = 0;
    public static bool $forcedReauthentication = false;
    private static ?OAuthProfile $profile = null;

    public function __construct(private readonly RequestStack $requests)
    {
    }

    public static function reset(): void
    {
        self::$profileFetches = 0;
        self::$forcedReauthentication = false;
        self::$profile = new OAuthProfile(
            'google',
            'google-subject-123',
            'social@example.test',
            'Social Reader',
            true,
            true,
        );
    }

    public static function profile(OAuthProfile $profile): void
    {
        self::$profile = $profile;
    }

    public function providers(): array
    {
        return ['google'];
    }

    public function availability(): array
    {
        return ['google' => true];
    }

    public function isKnown(string $provider): bool
    {
        return $provider === 'google';
    }

    public function isEnabled(string $provider): bool
    {
        return $provider === 'google';
    }

    public function authorizationRedirect(string $provider, bool $forceReauthentication = false): RedirectResponse
    {
        $request = $this->requests->getCurrentRequest();
        if ($request === null) {
            throw new \LogicException('The fake OAuth client needs a request.');
        }
        self::$forcedReauthentication = $forceReauthentication;
        $request->getSession()->set(self::STATE_KEY, self::STATE);

        return new RedirectResponse('https://accounts.google.test/authorize?'.http_build_query([
            'state' => self::STATE,
            'scope' => 'openid email profile',
        ]));
    }

    public function fetchProfile(string $provider, Request $request): OAuthProfile
    {
        $expected = $request->getSession()->remove(self::STATE_KEY);
        $state = $request->query->all()['state'] ?? null;
        if ($expected !== self::STATE || $state !== self::STATE) {
            throw new InvalidStateException('Invalid state.');
        }
        if (($request->query->all()['code'] ?? null) !== 'functional-test-code') {
            throw new MissingAuthorizationCodeException('Missing code.');
        }

        ++self::$profileFetches;

        return self::$profile ?? throw new \LogicException('Reset the fake OAuth client before use.');
    }
}
