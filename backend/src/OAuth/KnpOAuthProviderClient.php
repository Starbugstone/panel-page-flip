<?php

declare(strict_types=1);

namespace App\OAuth;

use KnpU\OAuth2ClientBundle\Client\ClientRegistry;
use KnpU\OAuth2ClientBundle\Client\OAuth2Client;
use KnpU\OAuth2ClientBundle\Exception\InvalidStateException;
use League\OAuth2\Client\Provider\GoogleUser;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;

final class KnpOAuthProviderClient implements OAuthProviderClientInterface
{
    private const GOOGLE = 'google';

    public function __construct(
        private readonly ClientRegistry $clients,
        #[Autowire('%env(OAUTH_GOOGLE_CLIENT_ID)%')]
        private readonly string $googleClientId,
        #[Autowire('%env(OAUTH_GOOGLE_CLIENT_SECRET)%')]
        private readonly string $googleClientSecret,
    ) {
    }

    public function providers(): array
    {
        return [self::GOOGLE];
    }

    public function availability(): array
    {
        $availability = [];
        foreach ($this->providers() as $provider) {
            $availability[$provider] = $this->isEnabled($provider);
        }

        return $availability;
    }

    public function isKnown(string $provider): bool
    {
        return in_array(strtolower($provider), $this->providers(), true);
    }

    public function isEnabled(string $provider): bool
    {
        return strtolower($provider) === self::GOOGLE
            && trim($this->googleClientId) !== ''
            && trim($this->googleClientSecret) !== '';
    }

    public function authorizationRedirect(string $provider, bool $forceReauthentication = false): RedirectResponse
    {
        $client = $this->client($provider);

        // Explicit and minimal. In particular, this does not request Drive or
        // any provider API permission unrelated to signing in.
        return $client->redirect(['openid', 'email', 'profile'], [
            'prompt' => $forceReauthentication ? 'login' : 'select_account',
            ...($forceReauthentication ? ['max_age' => 0] : []),
        ]);
    }

    public function fetchProfile(string $provider, Request $request): OAuthProfile
    {
        $client = $this->client($provider);
        $session = $request->getSession();
        $expectedState = $session->remove(OAuth2Client::OAUTH2_SESSION_STATE_KEY);
        $actualState = $request->query->all()['state'] ?? null;

        // Consume the expected value before any network call. A valid callback
        // is single-use, and an invalid callback cannot use this installation
        // as a token-endpoint proxy.
        if (!is_string($expectedState) || $expectedState === ''
            || !is_string($actualState) || $actualState === ''
            || !hash_equals($expectedState, $actualState)) {
            throw new InvalidStateException('Invalid OAuth state.');
        }

        // State was checked and removed above, before the authorization code is
        // exchanged. The bundle must not try to read the now-consumed value a
        // second time.
        $client->setAsStateless();
        $resourceOwner = $client->fetchUser();

        return match (strtolower($provider)) {
            self::GOOGLE => $this->googleProfile($resourceOwner),
            default => throw new \InvalidArgumentException('Unknown OAuth provider.'),
        };
    }

    private function client(string $provider): OAuth2Client
    {
        $provider = strtolower($provider);
        if (!$this->isKnown($provider)) {
            throw new \InvalidArgumentException('Unknown OAuth provider.');
        }
        if (!$this->isEnabled($provider)) {
            throw new \DomainException('OAuth provider is not configured.');
        }

        $client = $this->clients->getClient($provider);
        if (!$client instanceof OAuth2Client) {
            throw new \LogicException('OAuth client does not support stateful authorization.');
        }

        return $client;
    }

    private function googleProfile(object $resourceOwner): OAuthProfile
    {
        if (!$resourceOwner instanceof GoogleUser) {
            throw new \UnexpectedValueException('Google returned an unsupported profile.');
        }

        $subject = $resourceOwner->getId();
        $email = $resourceOwner->getEmail();
        if (!is_string($subject) || trim($subject) === '') {
            throw new \UnexpectedValueException('Google did not return a stable account identifier.');
        }
        if (!is_string($email) || trim($email) === '') {
            throw new \UnexpectedValueException('Google did not return an email address.');
        }

        $name = $resourceOwner->getName();

        return new OAuthProfile(
            self::GOOGLE,
            trim($subject),
            trim($email),
            trim($name) === '' ? null : trim($name),
            $resourceOwner->getEmailVerified() === true,
            $resourceOwner->isEmailTrustworthy(),
        );
    }
}
