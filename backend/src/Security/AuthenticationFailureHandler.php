<?php

namespace App\Security;

use App\Repository\UserRepository;
use App\Service\SecurityAuditLogger;
use Psr\Log\LogLevel;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authentication\AuthenticationFailureHandlerInterface;

class AuthenticationFailureHandler implements AuthenticationFailureHandlerInterface
{
    public function __construct(
        private readonly SecurityAuditLogger $auditLogger,
        private readonly UserRepository $userRepository,
    ) {
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        $this->record($request, $exception);

        // The one failure the client is told the reason for, because there is
        // something the user can do about it and the address is their own.
        if ($exception instanceof UnverifiedEmailException) {
            return new JsonResponse([
                'success' => false,
                'message' => $exception->getMessageKey(),
                'requiresVerification' => true,
                'email' => $exception->getEmail(),
            ], Response::HTTP_FORBIDDEN);
        }

        // Everything else gets one indistinguishable answer. The internal
        // message used to be echoed back, which distinguishes "no such user"
        // from "wrong password" and turns the login form into an account
        // enumeration oracle.
        return new JsonResponse([
            'success' => false,
            'message' => 'Invalid credentials.',
        ], Response::HTTP_UNAUTHORIZED);
    }

    /**
     * Log the attempt without turning the security log into a list of addresses
     * attackers have typed.
     *
     * The submitted address is resolved to an account id and then discarded. A
     * real account that is under attack is identified precisely; an attempt
     * against an address that does not exist here records only that fact, so
     * spraying a leaked address list leaves a count and not a copy of the list.
     */
    private function record(Request $request, AuthenticationException $exception): void
    {
        $userId = $this->resolveUserId($request, $exception);

        // An unverified account is a real credential match, so it is not an
        // authentication failure in the sense that matters here — counting it
        // towards a brute-force threshold would alert on somebody who simply
        // has not clicked their verification link yet.
        if ($exception instanceof UnverifiedEmailException) {
            $this->auditLogger->security(
                SecurityAuditLogger::AUTHENTICATION_UNVERIFIED,
                ['actor_user_id' => $userId],
                LogLevel::INFO
            );

            return;
        }

        $context = [
            'actor_user_id' => $userId,
            'account_resolved' => $userId !== null,
            'user_agent' => $request->headers->get('User-Agent'),
            'reason' => $exception::class,
        ];

        // Counted per address, because that is what a brute force has in common
        // across attempts — one attacker trying a thousand accounts must not be
        // spread across a thousand separate thresholds.
        $this->auditLogger->suspicious(
            SecurityAuditLogger::AUTHENTICATION_FAILED,
            'ip:' . $this->auditLogger->clientIp(),
            $context,
            $this->auditLogger->failedLoginThreshold()
        );
    }

    private function resolveUserId(Request $request, AuthenticationException $exception): ?int
    {
        // The unverified case already carries the address it resolved, so there
        // is no reason to parse the body again — or to guess at where the
        // authenticator read it from.
        if ($exception instanceof UnverifiedEmailException) {
            $email = $exception->getEmail();
        } else {
            $payload = json_decode((string) $request->getContent(), true);
            $email = is_array($payload) ? ($payload['email'] ?? null) : null;
            // The firewall is json_login today. The parameter bag is where a
            // form login would put it, and an attempt recorded with no account
            // makes the "this account is under attack" signal useless.
            $email ??= $request->request->get('email');
        }

        if (!is_string($email) || $email === '') {
            return null;
        }

        return $this->userRepository->findOneBy(['email' => $email])?->getId();
    }
}
