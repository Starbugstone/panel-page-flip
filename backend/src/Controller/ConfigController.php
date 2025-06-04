<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

#[Route('/api/config', name: 'api_config_')]
class ConfigController extends AbstractController
{
    #[Route('', name: 'get', methods: ['GET'])]
    public function getConfig(ParameterBagInterface $params): JsonResponse
    {
        // Get the current user
        $user = $this->getUser();
        if (!$user) {
            return $this->json(['message' => 'User not authenticated'], JsonResponse::HTTP_UNAUTHORIZED);
        }

        // Return only the configuration values that are safe to expose to the frontend
        return $this->json([
            'upload' => [
                'maxConcurrentUploads' => (int)$params->get('max_concurrent_uploads'),
            ]
        ]);
    }
}
