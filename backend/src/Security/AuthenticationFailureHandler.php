<?php

namespace App\Security;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authentication\AuthenticationFailureHandlerInterface;

class AuthenticationFailureHandler implements AuthenticationFailureHandlerInterface
{
    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
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
}
