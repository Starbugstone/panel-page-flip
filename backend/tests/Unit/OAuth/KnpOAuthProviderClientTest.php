<?php

declare(strict_types=1);

namespace App\Tests\Unit\OAuth;

use App\OAuth\KnpOAuthProviderClient;
use KnpU\OAuth2ClientBundle\Client\ClientRegistry;
use KnpU\OAuth2ClientBundle\Client\OAuth2Client;
use KnpU\OAuth2ClientBundle\Exception\InvalidStateException;
use League\OAuth2\Client\Provider\GoogleUser;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

final class KnpOAuthProviderClientTest extends TestCase
{
    public function testGoogleIsAvailableOnlyWithBothServerCredentials(): void
    {
        $registry = $this->createMock(ClientRegistry::class);

        self::assertSame(
            ['google' => false],
            (new KnpOAuthProviderClient($registry, '', 'secret'))->availability(),
        );
        self::assertSame(
            ['google' => false],
            (new KnpOAuthProviderClient($registry, 'client-id', ''))->availability(),
        );
        self::assertSame(
            ['google' => true],
            (new KnpOAuthProviderClient($registry, 'client-id', 'secret'))->availability(),
        );
    }

    public function testGoogleRedirectUsesOnlyIdentityScopes(): void
    {
        $oauthClient = $this->createMock(OAuth2Client::class);
        $oauthClient->expects(self::once())
            ->method('redirect')
            ->with(['openid', 'email', 'profile'], ['prompt' => 'select_account'])
            ->willReturn(new RedirectResponse('https://accounts.google.test/authorize'));

        $registry = $this->createMock(ClientRegistry::class);
        $registry->method('getClient')->with('google')->willReturn($oauthClient);
        $client = new KnpOAuthProviderClient($registry, 'client-id', 'client-secret');

        self::assertSame('https://accounts.google.test/authorize', $client->authorizationRedirect('google')->getTargetUrl());
    }

    public function testHighRiskReauthenticationForcesAFreshProviderLogin(): void
    {
        $oauthClient = $this->createMock(OAuth2Client::class);
        $oauthClient->expects(self::once())
            ->method('redirect')
            ->with(['openid', 'email', 'profile'], ['prompt' => 'login', 'max_age' => 0])
            ->willReturn(new RedirectResponse('https://accounts.google.test/authorize'));

        $client = new KnpOAuthProviderClient($this->registry($oauthClient), 'client-id', 'client-secret');

        self::assertSame(
            'https://accounts.google.test/authorize',
            $client->authorizationRedirect('google', true)->getTargetUrl(),
        );
    }

    public function testCallbackConsumesStateBeforeFetchingTheProfile(): void
    {
        $request = Request::create('/api/auth/oauth/google/callback?state=expected-state&code=code');
        $request->setSession($session = new Session(new MockArraySessionStorage()));
        $session->set(OAuth2Client::OAUTH2_SESSION_STATE_KEY, 'expected-state');

        $oauthClient = $this->createMock(OAuth2Client::class);
        $oauthClient->expects(self::once())->method('setAsStateless');
        $oauthClient->expects(self::once())->method('fetchUser')->willReturn(new GoogleUser([
            'sub' => 'google-subject',
            'email' => 'reader@gmail.com',
            'email_verified' => true,
            'name' => 'Reader',
        ]));
        $client = new KnpOAuthProviderClient($this->registry($oauthClient), 'client-id', 'client-secret');

        $profile = $client->fetchProfile('google', $request);

        self::assertFalse($session->has(OAuth2Client::OAUTH2_SESSION_STATE_KEY));
        self::assertSame('google-subject', $profile->subject);
        self::assertSame('reader@gmail.com', $profile->email);
        self::assertTrue($profile->emailVerified);
        self::assertTrue($profile->emailAuthoritative);
    }

    public function testInvalidStateIsConsumedAndRefusedBeforeAnyNetworkFetch(): void
    {
        $request = Request::create('/api/auth/oauth/google/callback?state=wrong-state&code=code');
        $request->setSession($session = new Session(new MockArraySessionStorage()));
        $session->set(OAuth2Client::OAUTH2_SESSION_STATE_KEY, 'expected-state');

        $oauthClient = $this->createMock(OAuth2Client::class);
        $oauthClient->expects(self::never())->method('fetchUser');
        $client = new KnpOAuthProviderClient($this->registry($oauthClient), 'client-id', 'client-secret');

        try {
            $client->fetchProfile('google', $request);
            self::fail('Invalid state should be refused.');
        } catch (InvalidStateException) {
            self::assertFalse($session->has(OAuth2Client::OAUTH2_SESSION_STATE_KEY));
        }
    }

    private function registry(OAuth2Client $oauthClient): ClientRegistry
    {
        $registry = $this->createMock(ClientRegistry::class);
        $registry->method('getClient')->with('google')->willReturn($oauthClient);

        return $registry;
    }
}
