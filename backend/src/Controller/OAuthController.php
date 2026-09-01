<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Entity\UserOAuthIdentity;
use App\Http\JsonRequestDecoder;
use App\OAuth\OAuthProfile;
use App\OAuth\OAuthProviderClientInterface;
use App\OAuth\OAuthSessionState;
use App\Repository\UserOAuthIdentityRepository;
use App\Security\OAuthAuthenticator;
use App\Security\UnverifiedEmailException;
use App\Security\UserChecker;
use App\Service\ApiRateLimiter;
use App\Service\EmailVerificationMailer;
use App\Service\EmailVerificationService;
use App\Service\SecurityAuditLogger;
use App\Service\ShareException;
use App\Service\UsernamePolicy;
use App\Service\UsernameService;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use KnpU\OAuth2ClientBundle\Exception\InvalidStateException;
use KnpU\OAuth2ClientBundle\Exception\MissingAuthorizationCodeException;
use League\OAuth2\Client\Provider\Exception\IdentityProviderException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/auth', name: 'api_oauth_')]
final class OAuthController extends AbstractController
{
    private const PROVIDER_REQUIREMENT = '[a-z][a-z0-9_-]{0,31}';

    #[Route('/providers', name: 'providers', methods: ['GET'])]
    public function providers(OAuthProviderClientInterface $providers): JsonResponse
    {
        return $this->json($providers->availability());
    }

    #[Route('/oauth/{provider}/start', name: 'start', methods: ['GET'], requirements: ['provider' => self::PROVIDER_REQUIREMENT])]
    public function start(
        string $provider,
        Request $request,
        OAuthProviderClientInterface $providers,
        OAuthSessionState $oauthSession,
        UserOAuthIdentityRepository $identities,
        ApiRateLimiter $rateLimiter,
    ): Response {
        $provider = strtolower($provider);
        if (!$providers->isKnown($provider) || !$providers->isEnabled($provider)) {
            return $this->json(['message' => 'This sign-in provider is not available.'], Response::HTTP_NOT_FOUND);
        }

        if ($limited = $rateLimiter->limit($request, 'oauth_start')) {
            return $limited;
        }

        $user = $this->currentUser();
        $purpose = $request->query->all()['purpose'] ?? null;
        $mode = $user === null ? 'login' : 'connect';
        if ($purpose === 'delete-account') {
            if ($user === null) {
                return $this->json(['message' => 'Sign in before reauthenticating.'], Response::HTTP_UNAUTHORIZED);
            }
            if ($identities->findForUser($user, $provider) === null) {
                return $this->json(['message' => 'That provider is not connected to this account.'], Response::HTTP_CONFLICT);
            }
            $mode = 'reauth';
        }

        $redirect = $this->localRedirect($request->query->all()['redirect'] ?? null);
        if ($mode === 'reauth') {
            $redirect = '/settings';
        }

        // Beginning a new provider round-trip supersedes any unfinished social
        // signup in this browser, so onboarding can never consume stale
        // identity data from an earlier attempt.
        $oauthSession->clearPending($request->getSession());
        $oauthSession->startFlow($request->getSession(), $provider, $mode, $redirect, $user?->getId());

        try {
            return $providers->authorizationRedirect($provider, $mode === 'reauth');
        } catch (\Throwable) {
            $oauthSession->clearFlow($request->getSession());

            return $this->json(['message' => 'Could not start social sign-in. Please try again.'], Response::HTTP_SERVICE_UNAVAILABLE);
        }
    }

    #[Route('/oauth/{provider}/callback', name: 'callback', methods: ['GET'], requirements: ['provider' => self::PROVIDER_REQUIREMENT])]
    public function callback(
        string $provider,
        Request $request,
        OAuthProviderClientInterface $providers,
        OAuthSessionState $oauthSession,
        UserOAuthIdentityRepository $identities,
        EntityManagerInterface $entityManager,
        UserChecker $userChecker,
        Security $security,
        SecurityAuditLogger $auditLogger,
        ApiRateLimiter $rateLimiter,
    ): Response {
        $provider = strtolower($provider);
        if (!$providers->isKnown($provider) || !$providers->isEnabled($provider)) {
            return $this->oauthFailure(null, 'unavailable', $provider);
        }

        if ($limited = $rateLimiter->limit($request, 'oauth_callback')) {
            return $limited;
        }

        $flow = $oauthSession->consumeFlow($request->getSession(), $provider);
        if ($flow === null) {
            $auditLogger->security(SecurityAuditLogger::OAUTH_CALLBACK_REJECTED, [
                'provider' => $provider,
                'reason' => 'missing_or_expired_flow',
            ]);

            return $this->oauthFailure(null, 'expired', $provider);
        }

        try {
            $profile = $providers->fetchProfile($provider, $request);
        } catch (InvalidStateException) {
            $auditLogger->security(SecurityAuditLogger::OAUTH_CALLBACK_REJECTED, [
                'provider' => $provider,
                'reason' => 'invalid_state',
            ]);

            return $this->oauthFailure($flow, 'invalid_state', $provider);
        } catch (MissingAuthorizationCodeException|IdentityProviderException|\UnexpectedValueException) {
            $auditLogger->security(SecurityAuditLogger::OAUTH_CALLBACK_REJECTED, [
                'provider' => $provider,
                'reason' => 'provider_response_refused',
            ]);

            return $this->oauthFailure($flow, 'cancelled', $provider);
        } catch (\Throwable) {
            $auditLogger->security(SecurityAuditLogger::OAUTH_CALLBACK_REJECTED, [
                'provider' => $provider,
                'reason' => 'callback_failed',
            ]);

            return $this->oauthFailure($flow, 'failed', $provider);
        }

        if (strtolower($profile->provider) !== $provider) {
            $auditLogger->security(SecurityAuditLogger::OAUTH_CALLBACK_REJECTED, [
                'provider' => $provider,
                'reason' => 'provider_profile_mismatch',
            ]);

            return $this->oauthFailure($flow, 'failed', $provider);
        }

        if ($flow['mode'] === 'connect') {
            return $this->connect($flow, $profile, $request, $identities, $entityManager, $auditLogger);
        }
        if ($flow['mode'] === 'reauth') {
            return $this->reauthenticate($flow, $profile, $request, $identities, $entityManager, $oauthSession, $auditLogger);
        }

        $identity = $identities->findIdentity($provider, $profile->subject);
        if ($identity !== null) {
            $identity->setProviderEmail($profile->email)->markUsed();

            try {
                // Programmatic login invokes the firewall's session strategy;
                // explicitly call the same post-auth account gate first because
                // Security::login() itself only performs the pre-auth half.
                $userChecker->checkPostAuth($identity->getUser());
                $request->attributes->set('_oauth_provider', $provider);
                $security->login($identity->getUser(), OAuthAuthenticator::class, 'main');
            } catch (UnverifiedEmailException) {
                return $this->oauthFailure($flow, 'verification_required', $provider);
            } catch (\Throwable) {
                return $this->oauthFailure($flow, 'failed', $provider);
            }

            return new RedirectResponse($flow['redirect']);
        }

        // Email is a contact address, never an external identity key. A match
        // is an instruction to sign in first, not permission to merge accounts.
        $existingEmail = $entityManager->getRepository(User::class)->findOneBy(['email' => $profile->email]);
        if ($existingEmail !== null) {
            $auditLogger->security(SecurityAuditLogger::OAUTH_PROVIDER_LINK_REFUSED, [
                'provider' => $provider,
                'reason' => 'existing_local_email',
            ]);

            return $this->oauthFailure($flow, 'account_exists', $provider);
        }

        $oauthSession->storePending($request->getSession(), $profile, $flow['redirect']);

        return new RedirectResponse('/complete-social-signup');
    }

    #[Route('/oauth/pending', name: 'pending', methods: ['GET'])]
    public function pending(Request $request, OAuthSessionState $oauthSession, UsernameService $usernames): JsonResponse
    {
        $pending = $oauthSession->pending($request->getSession());
        if ($pending === null) {
            return $this->json(['message' => 'This social signup has expired. Start again.'], Response::HTTP_GONE);
        }

        return $this->json([
            'provider' => $pending['provider'],
            'email' => $pending['email'],
            'name' => $pending['name'],
            'suggestedUsername' => $usernames->suggest(),
        ]);
    }

    #[Route('/oauth/complete-registration', name: 'complete_registration', methods: ['POST'])]
    public function completeRegistration(
        Request $request,
        OAuthSessionState $oauthSession,
        UserOAuthIdentityRepository $identities,
        EntityManagerInterface $entityManager,
        UsernameService $usernames,
        EmailVerificationService $verification,
        EmailVerificationMailer $verificationMailer,
        SecurityAuditLogger $auditLogger,
        UserChecker $userChecker,
        Security $security,
        ApiRateLimiter $rateLimiter,
    ): JsonResponse {
        if ($this->currentUser() !== null) {
            return $this->json(['message' => 'This browser is already signed in.'], Response::HTTP_FORBIDDEN);
        }
        if ($limited = $rateLimiter->limit($request, 'oauth_registration')) {
            return $limited;
        }

        $pending = $oauthSession->pending($request->getSession());
        if ($pending === null) {
            return $this->json(['message' => 'This social signup has expired. Start again.'], Response::HTTP_GONE);
        }

        $data = JsonRequestDecoder::decode($request);
        if (($data['agreeTerms'] ?? null) !== true) {
            return $this->json([
                'message' => 'Validation failed',
                'errors' => ['agreeTerms' => 'You must agree to the Terms of Service'],
            ], Response::HTTP_BAD_REQUEST);
        }

        $requestedUsername = isset($data['username']) && is_string($data['username'])
            ? UsernamePolicy::stripPrefix($data['username'])
            : '';

        if ($identities->findIdentity($pending['provider'], $pending['subject']) !== null) {
            return $this->json(['message' => 'This provider account was linked in another request. Start again.'], Response::HTTP_CONFLICT);
        }
        if ($entityManager->getRepository(User::class)->findOneBy(['email' => $pending['email']]) !== null) {
            return $this->json([
                'message' => 'An account already exists with this email. Sign in with your existing method, then connect the provider from Settings.',
            ], Response::HTTP_CONFLICT);
        }

        $user = (new User())
            ->setEmail($pending['email'])
            ->setName($pending['name'])
            ->setRoles(['ROLE_USER'])
            ->setPassword(null)
            ->setIsEmailVerified($pending['emailVerified'] && $pending['emailAuthoritative']);

        try {
            $usernames->assign($user, $requestedUsername !== '' ? $requestedUsername : $usernames->suggest());
        } catch (ShareException $exception) {
            return $this->json([
                'message' => 'Validation failed',
                'errors' => ['username' => $exception->getMessage()],
                'suggestion' => $usernames->suggest(),
            ], $exception->getStatusCode());
        }

        $identity = new UserOAuthIdentity($user, $pending['provider'], $pending['subject'], $pending['email']);
        $identity->markUsed();
        $user->addOAuthIdentity($identity);

        try {
            $entityManager->wrapInTransaction(function (EntityManagerInterface $manager) use ($user, $identity): void {
                $manager->persist($user);
                $manager->persist($identity);
            });
        } catch (UniqueConstraintViolationException) {
            return $this->json([
                'message' => 'This signup conflicted with another request. Please start again.',
            ], Response::HTTP_CONFLICT);
        }

        $oauthSession->clearPending($request->getSession());
        $auditLogger->audit(SecurityAuditLogger::USER_REGISTERED, [
            'actor_user_id' => $user->getId(),
            'target_user_id' => $user->getId(),
            'target_type' => 'user',
            'created_by_admin' => false,
            'method' => 'oauth',
            'provider' => $pending['provider'],
        ]);
        $auditLogger->audit(SecurityAuditLogger::OAUTH_PROVIDER_LINKED, [
            'actor_user_id' => $user->getId(),
            'target_type' => 'user',
            'target_id' => $user->getId(),
            'provider' => $pending['provider'],
        ]);

        if (!$user->isEmailVerified()) {
            $plainToken = $verification->issue($user);
            $verificationMailer->send($user, $plainToken);

            return $this->json([
                'message' => 'Account created. Verify your email before signing in.',
                'requiresVerification' => true,
                'email' => $user->getEmail(),
                'redirect' => $pending['redirect'],
            ], Response::HTTP_CREATED);
        }

        $userChecker->checkPostAuth($user);
        $request->attributes->set('_oauth_provider', $pending['provider']);
        $security->login($user, OAuthAuthenticator::class, 'main');

        return $this->json([
            'message' => 'Account created and signed in.',
            'requiresVerification' => false,
            'redirect' => $pending['redirect'],
        ], Response::HTTP_CREATED);
    }

    #[Route('/oauth/connections', name: 'connections', methods: ['GET'])]
    public function connections(OAuthProviderClientInterface $providers, UserOAuthIdentityRepository $identities): JsonResponse
    {
        $user = $this->authenticatedUser();
        $connections = [];
        foreach ($providers->providers() as $provider) {
            $identity = $identities->findForUser($user, $provider);
            $connections[] = [
                'provider' => $provider,
                'enabled' => $providers->isEnabled($provider),
                'connected' => $identity !== null,
                'email' => $identity?->getProviderEmail(),
                'createdAt' => $identity?->getCreatedAt()->format(\DateTimeInterface::ATOM),
                'lastUsedAt' => $identity?->getLastUsedAt()?->format(\DateTimeInterface::ATOM),
            ];
        }

        return $this->json([
            'hasPassword' => $user->hasPassword(),
            'providers' => $connections,
        ]);
    }

    #[Route('/oauth/{provider}', name: 'disconnect', methods: ['DELETE'], requirements: ['provider' => self::PROVIDER_REQUIREMENT])]
    public function disconnect(
        string $provider,
        OAuthProviderClientInterface $providers,
        UserOAuthIdentityRepository $identities,
        EntityManagerInterface $entityManager,
        SecurityAuditLogger $auditLogger,
    ): JsonResponse {
        $provider = strtolower($provider);
        if (!$providers->isKnown($provider)) {
            return $this->json(['message' => 'Unknown sign-in provider.'], Response::HTTP_NOT_FOUND);
        }

        $user = $this->authenticatedUser();
        $identity = $identities->findForUser($user, $provider);
        if ($identity === null) {
            return $this->json(['message' => 'That provider is not connected.'], Response::HTTP_NOT_FOUND);
        }
        if (!$user->hasPassword() && $user->getOAuthIdentities()->count() <= 1) {
            return $this->json([
                'message' => 'Set a password or connect another sign-in method before disconnecting this provider.',
            ], Response::HTTP_CONFLICT);
        }

        $user->removeOAuthIdentity($identity);
        $entityManager->remove($identity);
        $entityManager->flush();

        $auditLogger->audit(SecurityAuditLogger::OAUTH_PROVIDER_DISCONNECTED, [
            'actor_user_id' => $user->getId(),
            'target_type' => 'user',
            'target_id' => $user->getId(),
            'provider' => $provider,
        ]);

        return $this->json(['message' => ucfirst($provider).' disconnected.']);
    }

    /** @param array{provider: string, mode: string, redirect: string, userId: int|null, expiresAt: int} $flow */
    private function connect(
        array $flow,
        OAuthProfile $profile,
        Request $request,
        UserOAuthIdentityRepository $identities,
        EntityManagerInterface $entityManager,
        SecurityAuditLogger $auditLogger,
    ): Response {
        $user = $this->currentUser();
        if ($user === null || $flow['userId'] !== $user->getId()) {
            return $this->oauthFailure($flow, 'sign_in_required', $profile->provider);
        }

        $existing = $identities->findIdentity($profile->provider, $profile->subject);
        if ($existing !== null && $existing->getUser()->getId() !== $user->getId()) {
            $auditLogger->security(SecurityAuditLogger::OAUTH_PROVIDER_LINK_REFUSED, [
                'actor_user_id' => $user->getId(),
                'provider' => $profile->provider,
                'reason' => 'owned_by_another_user',
            ]);

            return $this->oauthFailure($flow, 'identity_in_use', $profile->provider);
        }

        if ($existing === null) {
            $existing = new UserOAuthIdentity($user, $profile->provider, $profile->subject, $profile->email);
            $user->addOAuthIdentity($existing);
            $entityManager->persist($existing);
        }
        $existing->setProviderEmail($profile->email)->markUsed();

        try {
            $entityManager->flush();
        } catch (UniqueConstraintViolationException) {
            $auditLogger->security(SecurityAuditLogger::OAUTH_PROVIDER_LINK_REFUSED, [
                'actor_user_id' => $user->getId(),
                'provider' => $profile->provider,
                'reason' => 'link_race',
            ]);

            return $this->oauthFailure($flow, 'identity_in_use', $profile->provider);
        }

        $auditLogger->audit(SecurityAuditLogger::OAUTH_PROVIDER_LINKED, [
            'actor_user_id' => $user->getId(),
            'target_type' => 'user',
            'target_id' => $user->getId(),
            'provider' => $profile->provider,
        ]);

        return new RedirectResponse($this->withQuery($flow['redirect'], [
            'oauth_connected' => $profile->provider,
        ]));
    }

    /** @param array{provider: string, mode: string, redirect: string, userId: int|null, expiresAt: int} $flow */
    private function reauthenticate(
        array $flow,
        OAuthProfile $profile,
        Request $request,
        UserOAuthIdentityRepository $identities,
        EntityManagerInterface $entityManager,
        OAuthSessionState $oauthSession,
        SecurityAuditLogger $auditLogger,
    ): Response {
        $user = $this->currentUser();
        $identity = $identities->findIdentity($profile->provider, $profile->subject);
        if ($user === null || $flow['userId'] !== $user->getId()
            || $identity === null || $identity->getUser()->getId() !== $user->getId()) {
            $auditLogger->security(SecurityAuditLogger::OAUTH_CALLBACK_REJECTED, [
                'actor_user_id' => $user?->getId(),
                'provider' => $profile->provider,
                'reason' => 'reauth_identity_mismatch',
            ]);

            return $this->oauthFailure($flow, 'wrong_account', $profile->provider);
        }

        $identity->setProviderEmail($profile->email)->markUsed();
        $entityManager->flush();
        $oauthSession->markReauthenticated($request->getSession(), (int) $user->getId(), $profile->provider);

        return new RedirectResponse($this->withQuery($flow['redirect'], [
            'oauth_reauthenticated' => $profile->provider,
        ]));
    }

    /** @param array{provider: string, mode: string, redirect: string, userId: int|null, expiresAt: int}|null $flow */
    private function oauthFailure(?array $flow, string $error, string $provider): RedirectResponse
    {
        if (($flow['mode'] ?? null) === 'connect' || ($flow['mode'] ?? null) === 'reauth') {
            return new RedirectResponse($this->withQuery($flow['redirect'], [
                'oauth_error' => $error,
                'provider' => $provider,
            ]));
        }

        $redirect = $flow['redirect'] ?? '/dashboard';

        return new RedirectResponse($this->withQuery('/login', [
            'oauth_error' => $error,
            'provider' => $provider,
            'redirect' => $redirect,
        ]));
    }

    private function localRedirect(mixed $redirect): string
    {
        return is_string($redirect)
            && str_starts_with($redirect, '/')
            && !str_starts_with($redirect, '//')
            && !str_contains($redirect, '\\')
            && preg_match('/[\x00-\x1F\x7F]/', $redirect) !== 1
            ? $redirect
            : '/dashboard';
    }

    /** @param array<string, string> $parameters */
    private function withQuery(string $path, array $parameters): string
    {
        return $path.(str_contains($path, '?') ? '&' : '?').http_build_query($parameters);
    }

    private function currentUser(): ?User
    {
        $user = $this->getUser();

        return $user instanceof User ? $user : null;
    }

    private function authenticatedUser(): User
    {
        $user = $this->currentUser();
        if ($user === null) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }
}
