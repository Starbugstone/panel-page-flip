<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api', name: 'api_')]
class ApiTestController extends AbstractController
{
    #[Route('/ping', name: 'ping', methods: ['GET'])]
    public function ping(): JsonResponse
    {
        return $this->json([
            'message' => 'Ping successful!',
            'timestamp' => new \DateTime(),
        ]);
    }
    
    #[Route('/ping-auth', name: 'ping_auth', methods: ['GET'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')] // Ensures the user is logged in
    public function pingAuth(): JsonResponse
    {
        $user = $this->getUser();
        return $this->json([
            'message' => 'Authenticated ping successful!',
            'user_email' => $user ? $user->getUserIdentifier() : 'N/A',
        ]);
    }
}
