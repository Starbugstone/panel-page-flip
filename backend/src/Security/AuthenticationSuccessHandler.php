<?php

namespace App\Security;

use App\Entity\User;
use App\Service\SecurityAuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LogLevel;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Http\Authentication\AuthenticationSuccessHandlerInterface;

class AuthenticationSuccessHandler implements AuthenticationSuccessHandlerInterface
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly SecurityAuditLogger $auditLogger,
    ) {
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token): Response
    {
        /** @var User $user */
        $user = $token->getUser();

        // Nothing is refused here. By the time this runs the token is already
        // stored and the session cookie is already going out, so a 403 from
        // this method would be a refusal in name only — see {@see UserChecker},
        // which rejects unverified accounts during authentication instead.
        $user->setLastLoginAt(new \DateTimeImmutable());
        $this->entityManager->flush();

        // Info rather than warning: a login is not a problem. It is here so that
        // a failed-login burst can be read against the successful one that
        // followed it, which is the difference between a blocked attack and a
        // successful one.
        $this->auditLogger->security(
            SecurityAuditLogger::AUTHENTICATION_SUCCEEDED,
            [
                'actor_user_id' => $user->getId(),
                'is_admin' => $user->isAdmin(),
                'user_agent' => $request->headers->get('User-Agent'),
            ],
            LogLevel::INFO,
            SecurityAuditLogger::RESULT_SUCCESS
        );

        // Return a JSON response with user information
        return new JsonResponse([
            'success' => true,
            'user' => [
                'id' => $user->getId(),
                'email' => $user->getUserIdentifier(),
                'name' => $user->getName(),
                'roles' => $user->getRoles(),
                'hasPassword' => $user->hasPassword(),
            ],
            'message' => 'Login successful',
        ]);
    }
}
