<?php

namespace App\Controller;

use App\Entity\User;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Routing\Attribute\Route;

class MeController extends AbstractController
{
    #[Route('/api/me', name: 'api_me', methods: ['GET', 'POST'])]
    public function me(Request $request, SessionInterface $session, LoggerInterface $logger): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->json(['message' => 'User not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        $sessionRefreshed = false;
        if ($request->isMethod('POST')) {
            try {
                if (!$session->isStarted()) {
                    $session->start();
                }

                $session->set('last_activity', time());
                $sessionRefreshed = true;
            } catch (\Throwable $e) {
                $logger->warning('Session refresh failed.', ['exception' => $e]);
            }
        }

        return $this->json([
            'user' => [
                'id' => $user->getId(),
                'email' => $user->getEmail(),
                'name' => $user->getName(),
                'roles' => $user->getRoles(),
                'isAdmin' => in_array('ROLE_ADMIN', $user->getRoles(), true),
            ],
            'sessionRefreshed' => $sessionRefreshed,
        ]);
    }
}
