<?php

namespace App\Controller;

use App\Service\ComicFormatService;
use App\Service\MetadataProviderRegistry;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

#[Route('/api/config', name: 'api_config_')]
class ConfigController extends AbstractController
{
    #[Route('', name: 'get', methods: ['GET'])]
    public function getConfig(
        ParameterBagInterface $params,
        ComicFormatService $comicFormats,
        MetadataProviderRegistry $metadataProviders
    ): JsonResponse
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
                'comicFormats' => array_map(
                    static fn ($type): string => $type->value,
                    array_values(array_filter($comicFormats->enabled(), $comicFormats->isEnabled(...)))
                ),
            ],
            // Which external providers are usable, so a lookup can be aimed at
            // one instead of spending every provider's quota at once. Names
            // only — the credentials behind them never leave the server.
            'metadataProviders' => array_values(array_map(
                static fn (array $provider): array => ['key' => $provider['key'], 'label' => $provider['label']],
                array_filter($metadataProviders->status(), static fn (array $p): bool => $p['configured'])
            )),
        ]);
    }
}
