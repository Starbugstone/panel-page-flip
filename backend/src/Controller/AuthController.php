<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Service\SecurityAuditLogger;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Http\Event\LogoutEvent;

#[Route('/api', name: 'api_')]
final class AuthController extends AbstractController
{
    #[Route('/login', name: 'login', methods: ['POST'])]
    public function login(): JsonResponse
    {
        return $this->json(['message' => 'Login failed. Check your credentials.'], Response::HTTP_UNAUTHORIZED);
    }

    #[Route('/login_check', name: 'login_check', methods: ['GET'])]
    public function loginCheck(): JsonResponse
    {
        $user = $this->getUser();
        if ($user instanceof User) {
            return $this->json([
                'user' => ['email' => $user->getUserIdentifier(), 'roles' => $user->getRoles()],
                'message' => 'User is authenticated',
            ]);
        }

        return $this->json(['message' => 'User is not authenticated'], Response::HTTP_UNAUTHORIZED);
    }

    #[Route('/logout', name: 'logout', methods: ['POST'])]
    public function logout(
        TokenStorageInterface $tokenStorage,
        RequestStack $requestStack,
        EventDispatcherInterface $eventDispatcher,
        SecurityAuditLogger $securityLogger
    ): JsonResponse {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->json(['message' => 'No user to logout']);
        }

        $securityLogger->audit(SecurityAuditLogger::USER_LOGGED_OUT, [
            'actor_user_id' => $user->getId(),
            'target_user_id' => $user->getId(),
            'target_type' => 'user',
        ]);

        $request = $requestStack->getCurrentRequest();
        $token = $tokenStorage->getToken();
        if ($request !== null && $token !== null) {
            $eventDispatcher->dispatch(new LogoutEvent($request, $token));
        }
        $tokenStorage->setToken(null);

        return $this->json(['message' => 'Logout successful']);
    }
}
