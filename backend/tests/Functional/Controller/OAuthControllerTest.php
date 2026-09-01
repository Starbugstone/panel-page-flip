<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Entity\User;
use App\Entity\UserOAuthIdentity;
use App\OAuth\OAuthProfile;
use App\Repository\UserOAuthIdentityRepository;
use App\Tests\Factory\UserFactory;
use App\Tests\Functional\AbstractApiTestCase;
use App\Tests\Support\FakeOAuthProviderClient;
use Doctrine\ORM\EntityManagerInterface;

final class OAuthControllerTest extends AbstractApiTestCase
{
    protected function setUp(): void
    {
        FakeOAuthProviderClient::reset();
        parent::setUp();
    }

    public function testProviderDiscoveryIsPublicAndContainsNoCredentials(): void
    {
        $payload = $this->getJson('/api/auth/providers');

        self::assertResponseIsSuccessful();
        self::assertSame(['google' => true], $payload);
        self::assertStringNotContainsString('client', strtolower(json_encode($payload, JSON_THROW_ON_ERROR)));
        self::assertStringNotContainsString('secret', strtolower(json_encode($payload, JSON_THROW_ON_ERROR)));
    }

    public function testStartUsesMinimumScopesAndRejectsUnknownProvidersAndExternalRedirects(): void
    {
        $this->browser()->request('GET', '/api/auth/oauth/not-real/start');
        self::assertResponseStatusCodeSame(404);

        $this->browser()->request('GET', '/api/auth/oauth/google/start?redirect=https%3A%2F%2Fevil.example');
        self::assertResponseRedirects();
        $location = (string) $this->browser()->getResponse()->headers->get('Location');
        self::assertStringStartsWith('https://accounts.google.test/authorize?', $location);
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        self::assertSame('openid email profile', $query['scope']);

        $this->browser()->request('GET', '/api/auth/oauth/google/start?redirect=%2F%2Fevil.example');
        self::assertResponseRedirects();
        $this->completeCallback();
        self::assertResponseRedirects('/complete-social-signup');
        $payload = $this->postJson('/api/auth/oauth/complete-registration', [
            'username' => 'SocialReader123',
            'agreeTerms' => true,
        ]);
        self::assertResponseStatusCodeSame(201);
        self::assertSame('/dashboard', $payload['redirect']);
    }

    public function testInvalidAndReplayedStateAreRejectedBeforeAnotherProfileFetch(): void
    {
        $this->startLogin();
        $this->browser()->request('GET', '/api/auth/oauth/google/callback?state=wrong&code=functional-test-code');

        self::assertResponseRedirects();
        self::assertStringContainsString('oauth_error=invalid_state', (string) $this->browser()->getResponse()->headers->get('Location'));
        self::assertSame(0, FakeOAuthProviderClient::$profileFetches);

        $this->browser()->request('GET', '/api/auth/oauth/google/callback?state='.FakeOAuthProviderClient::STATE.'&code=functional-test-code');
        self::assertResponseRedirects();
        self::assertSame(0, FakeOAuthProviderClient::$profileFetches);
        self::assertStringContainsString('oauth_error=expired', (string) $this->browser()->getResponse()->headers->get('Location'));
    }

    public function testFirstLoginWaitsForTermsAndCreatesAValidPasswordlessAccount(): void
    {
        $this->startLogin('/sharing');
        $this->completeCallback();

        self::assertResponseRedirects('/complete-social-signup');
        self::assertSame(0, UserFactory::repository()->count());

        $pending = $this->getJson('/api/auth/oauth/pending');
        self::assertSame('google', $pending['provider']);
        self::assertSame('social@example.test', $pending['email']);

        $this->postJson('/api/auth/oauth/complete-registration', [
            'username' => 'SocialReader123',
            'agreeTerms' => false,
        ]);
        self::assertResponseStatusCodeSame(400);
        self::assertSame(0, UserFactory::repository()->count());

        $payload = $this->postJson('/api/auth/oauth/complete-registration', [
            'username' => 'SocialReader123',
            'agreeTerms' => true,
        ]);
        self::assertResponseStatusCodeSame(201);
        self::assertFalse($payload['requiresVerification']);
        self::assertSame('/sharing', $payload['redirect']);

        $user = UserFactory::repository()->findOneBy(['email' => 'social@example.test']);
        self::assertInstanceOf(User::class, $user);
        self::assertFalse($user->hasPassword());
        self::assertSame(['ROLE_USER'], array_values($user->getRoles()));
        self::assertSame('SocialReader123', $user->getUsername());
        self::assertNotSame('', $user->getUserCode());
        self::assertTrue($user->isEmailVerified());

        $identity = static::getContainer()->get(UserOAuthIdentityRepository::class)
            ->findIdentity('google', 'google-subject-123');
        self::assertSame($user->getId(), $identity?->getUser()->getId());
        self::assertNotNull($identity?->getLastUsedAt());

        $me = $this->getJson('/api/me');
        self::assertSame($user->getId(), $me['user']['id']);
        self::assertFalse($me['user']['hasPassword']);

        $this->browser()->restart();
        $this->postJson('/api/login', [
            'email' => $user->getEmail(),
            'password' => 'NotAStoredPassword!123',
        ]);
        self::assertResponseStatusCodeSame(401);
    }

    public function testThirdPartyGoogleEmailStillRequiresApplicationVerification(): void
    {
        FakeOAuthProviderClient::profile(new OAuthProfile(
            'google', 'third-party-subject', 'reader@example.test', 'Third Party Reader', true, false
        ));
        $this->startLogin();
        $this->completeCallback();

        $payload = $this->postJson('/api/auth/oauth/complete-registration', [
            'username' => 'ThirdPartyReader',
            'agreeTerms' => true,
        ]);

        self::assertResponseStatusCodeSame(201);
        self::assertTrue($payload['requiresVerification']);
        self::assertEmailCount(1);
        $user = UserFactory::repository()->findOneBy(['email' => 'reader@example.test']);
        self::assertInstanceOf(User::class, $user);
        self::assertFalse($user->isEmailVerified());
        self::assertNull($this->getJson('/api/me')['user']);
    }

    public function testAnExistingEmailIsNeverAutoLinkedOrDuplicated(): void
    {
        $existing = UserFactory::createOne(['email' => 'social@example.test']);
        $this->startLogin();
        $this->completeCallback();

        self::assertResponseRedirects();
        self::assertStringContainsString('oauth_error=account_exists', (string) $this->browser()->getResponse()->headers->get('Location'));
        self::assertSame(1, UserFactory::repository()->count());
        self::assertSame(0, static::getContainer()->get(UserOAuthIdentityRepository::class)->count([]));
        self::assertSame($existing->getId(), UserFactory::repository()->findOneBy(['email' => 'social@example.test'])?->getId());
    }

    public function testLinkedIdentityLogsIntoItsOwnerAndStillRunsUserChecker(): void
    {
        $owner = UserFactory::createOne(['email' => 'owner@example.test']);
        $identity = new UserOAuthIdentity($owner, 'google', 'google-subject-123', 'old@example.test');
        $this->persist($identity);

        $this->startLogin();
        $this->completeCallback();

        self::assertResponseRedirects('/dashboard');
        $me = $this->getJson('/api/me');
        self::assertSame($owner->getId(), $me['user']['id']);
        static::getContainer()->get(EntityManagerInterface::class)->clear();
        $usedIdentity = static::getContainer()->get(UserOAuthIdentityRepository::class)
            ->findIdentity('google', 'google-subject-123');
        self::assertSame('social@example.test', $usedIdentity?->getProviderEmail());
        self::assertNotNull($usedIdentity?->getLastUsedAt());
        self::assertNotNull($usedIdentity?->getUser()->getLastLoginAt());

        $this->browser()->restart();
        $unverified = UserFactory::new()->unverified()->create(['email' => 'unverified@example.test']);
        FakeOAuthProviderClient::profile(new OAuthProfile(
            'google', 'unverified-subject', 'unverified@example.test', null, true, true
        ));
        $this->persist(new UserOAuthIdentity($unverified, 'google', 'unverified-subject', 'unverified@example.test'));

        $this->startLogin();
        $this->completeCallback();
        self::assertStringContainsString('oauth_error=verification_required', (string) $this->browser()->getResponse()->headers->get('Location'));
        self::assertNull($this->getJson('/api/me')['user']);
    }

    public function testAuthenticatedUserCanConnectAndDisconnectWithoutMovingAnIdentity(): void
    {
        $user = $this->createAndLoginUser();
        $this->startLogin('/settings');
        $this->completeCallback();
        self::assertResponseRedirects('/settings?oauth_connected=google');

        $connections = $this->getJson('/api/auth/oauth/connections');
        self::assertTrue($connections['hasPassword']);
        self::assertTrue($connections['providers'][0]['connected']);
        self::assertSame('social@example.test', $connections['providers'][0]['email']);

        $this->deleteJson('/api/auth/oauth/google');
        self::assertResponseIsSuccessful();
        self::assertSame(0, static::getContainer()->get(UserOAuthIdentityRepository::class)->count([]));

        $other = UserFactory::createOne();
        $this->persist(new UserOAuthIdentity($other, 'google', 'google-subject-123', 'social@example.test'));
        $this->startLogin('/settings');
        $this->completeCallback();
        self::assertStringContainsString('oauth_error=identity_in_use', (string) $this->browser()->getResponse()->headers->get('Location'));
        self::assertSame($other->getId(), static::getContainer()->get(UserOAuthIdentityRepository::class)
            ->findIdentity('google', 'google-subject-123')?->getUser()->getId());
        self::assertNotSame($user->getId(), $other->getId());
    }

    public function testLastProviderCannotBeDisconnectedAndCanReauthenticateAccountDeletion(): void
    {
        $user = UserFactory::createOne(['password' => null]);
        $identity = new UserOAuthIdentity($user, 'google', 'google-subject-123', 'social@example.test');
        $this->persist($identity);
        $this->loginAs($user);

        $payload = $this->deleteJson('/api/auth/oauth/google');
        self::assertResponseStatusCodeSame(409);
        self::assertStringContainsString('Set a password', $payload['message']);

        $payload = $this->deleteJson('/api/privacy/account', ['confirmation' => 'DELETE']);
        self::assertResponseStatusCodeSame(403);
        self::assertTrue($payload['requiresProviderReauthentication']);

        $this->browser()->request('GET', '/api/auth/oauth/google/start?purpose=delete-account');
        self::assertResponseRedirects();
        self::assertTrue(FakeOAuthProviderClient::$forcedReauthentication);
        $this->completeCallback();
        self::assertResponseRedirects('/settings?oauth_reauthenticated=google');

        $this->deleteJson('/api/privacy/account', ['confirmation' => 'DELETE']);
        self::assertResponseIsSuccessful();
        self::assertNull(static::getContainer()->get(EntityManagerInterface::class)->find(User::class, $user->getId()));
        self::assertSame(0, static::getContainer()->get(UserOAuthIdentityRepository::class)->count([]));
    }

    public function testExportIncludesSafeIdentityMetadataAndNoSubject(): void
    {
        $user = $this->createAndLoginUser();
        $identity = (new UserOAuthIdentity($user, 'google', 'secret-subject', 'social@example.test'))->markUsed();
        $user->addOAuthIdentity($identity);
        $this->persist($identity);

        $payload = $this->getJson('/api/privacy/export');
        $encoded = json_encode($payload, JSON_THROW_ON_ERROR);
        self::assertSame('google', $payload['account']['connectedAccounts'][0]['provider']);
        self::assertSame('social@example.test', $payload['account']['connectedAccounts'][0]['providerEmail']);
        self::assertStringNotContainsString('secret-subject', $encoded);
        self::assertStringNotContainsString('token', strtolower($encoded));
    }

    private function startLogin(string $redirect = '/dashboard'): void
    {
        $this->browser()->request('GET', '/api/auth/oauth/google/start?redirect='.rawurlencode($redirect));
        self::assertResponseRedirects();
    }

    private function completeCallback(): void
    {
        $this->browser()->request(
            'GET',
            '/api/auth/oauth/google/callback?state='.FakeOAuthProviderClient::STATE.'&code=functional-test-code'
        );
    }

    private function persist(object $entity): void
    {
        $manager = static::getContainer()->get(EntityManagerInterface::class);
        $manager->persist($entity);
        $manager->flush();
    }
}
