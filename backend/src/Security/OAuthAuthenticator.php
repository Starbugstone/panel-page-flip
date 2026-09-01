<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use App\Service\SecurityAuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LogLevel;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;

/**
 * Creates the same Symfony session token as every other authenticator.
 *
 * OAuth profile and account resolution happen in the callback controller; this
 * authenticator is used programmatically only, after the external response has
 * already been validated. Keeping token creation here means successful social
 * login still runs Symfony's session strategy instead of writing a cookie by
 * hand.
 */
final class OAuthAuthenticator extends AbstractAuthenticator
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly SecurityAuditLogger $auditLogger,
    ) {
    }

    public function supports(Request $request): ?bool
    {
        return false;
    }

    public function authenticate(Request $request): Passport
    {
        throw new \LogicException('OAuthAuthenticator is only used for programmatic login.');
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        $user = $token->getUser();
        if (!$user instanceof User) {
            throw new \LogicException('OAuth authentication requires an application user.');
        }

        $provider = $request->attributes->get('_oauth_provider');
        $provider = is_string($provider) ? $provider : 'unknown';

        $user->setLastLoginAt(new \DateTimeImmutable());
        $this->entityManager->flush();

        $this->auditLogger->security(
            SecurityAuditLogger::AUTHENTICATION_SUCCEEDED,
            [
                'actor_user_id' => $user->getId(),
                'is_admin' => $user->isAdmin(),
                'method' => 'oauth',
                'provider' => $provider,
                'user_agent' => $request->headers->get('User-Agent'),
            ],
            LogLevel::INFO,
            SecurityAuditLogger::RESULT_SUCCESS,
        );

        return null;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        return null;
    }
}
