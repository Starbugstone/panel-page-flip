<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Http\Event\LogoutEvent;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\RequestStack;

#[Route('/api', name: 'api_')]
class AuthController extends AbstractController
{
    /**
     * This route is not actually called by the json_login authenticator,
     * but it's needed to define the path that will trigger the authenticator.
     * The authenticator is configured in security.yaml.
     */
    #[Route('/login', name: 'login', methods: ['POST'])]
    public function login(): JsonResponse
    {
        // This method will not be executed, as the json_login authenticator
        // will handle the login process before this method is called.
        
        // If we reach this point, it means authentication failed
        return $this->json([
            'message' => 'Login failed. Check your credentials.',
        ], Response::HTTP_UNAUTHORIZED);
    }

    #[Route('/login_check', name: 'login_check', methods: ['GET'])]
    public function loginCheck(): JsonResponse
    {
        // If the user is authenticated, return user info
        if ($this->getUser()) {
            return $this->json([
                'user' => [
                    'email' => $this->getUser()->getUserIdentifier(),
                    'roles' => $this->getUser()->getRoles(),
                ],
                'message' => 'User is authenticated',
            ]);
        }

        // If not authenticated, return an error
        return $this->json([
            'message' => 'User is not authenticated',
        ], Response::HTTP_UNAUTHORIZED);
    }
    
    /**
     * Programmatic logout endpoint that doesn't rely on Symfony's security.yaml configuration
     */
    #[Route('/logout_user', name: 'logout_user', methods: ['POST'])]
    public function logoutUser(
        TokenStorageInterface $tokenStorage,
        RequestStack $requestStack,
        EventDispatcherInterface $eventDispatcher
    ): JsonResponse
    {
        // Check if user is authenticated
        if (!$this->getUser()) {
            return $this->json([
                'message' => 'No user to logout',
            ]);
        }
        
        // Programmatically invalidate the current user session
        $logoutEvent = new LogoutEvent($requestStack->getCurrentRequest(), $tokenStorage->getToken());
        $eventDispatcher->dispatch($logoutEvent);
        $tokenStorage->setToken(null);
        
        // Return success response
        return $this->json([
            'message' => 'Logout successful',
        ]);
    }
}
