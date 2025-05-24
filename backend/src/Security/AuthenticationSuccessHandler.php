<?php

namespace App\Security;

use App\Entity\User;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authentication\AuthenticationSuccessHandlerInterface;

class AuthenticationSuccessHandler implements AuthenticationSuccessHandlerInterface
{
    public function onAuthenticationSuccess(Request $request, TokenInterface $token): Response
    {
        /** @var User $user */
        $user = $token->getUser();
        
        // Check if the user's email is verified
        if (!$user->isEmailVerified()) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Your email address is not verified. Please check your inbox for the verification email.',
                'requiresVerification' => true,
                'email' => $user->getUserIdentifier(),
            ], Response::HTTP_FORBIDDEN);
        }
        
        // Return a JSON response with user information
        return new JsonResponse([
            'success' => true,
            'user' => [
                'id' => $user->getId(),
                'email' => $user->getUserIdentifier(),
                'name' => $user->getName(),
                'roles' => $user->getRoles(),
            ],
            'message' => 'Login successful',
        ]);
    }
}
