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
    use RequiresAuthenticatedUser;

    #[Route('', name: 'get', methods: ['GET'])]
    public function getConfig(
        ParameterBagInterface $params,
        ComicFormatService $comicFormats,
        MetadataProviderRegistry $metadataProviders
    ): JsonResponse
    {
        $user = $this->requireUser();

        // Return only the configuration values that are safe to expose to the frontend
        return $this->json([
            'upload' => [
                'maxConcurrentUploads' => (int)$params->get('max_concurrent_uploads'),
                'maxParallelFileUploads' => (int)$params->get('max_parallel_file_uploads'),
                'maxChunkBytes' => (int)$params->get('upload_max_chunk_bytes'),
                'comicFormats' => array_map(
                    static fn ($type): string => $type->value,
                    array_values(array_filter($comicFormats->enabled(), $comicFormats->isEnabled(...)))
                ),
            ],
            // Which external providers would answer *this* user, so a lookup
            // can be aimed at one instead of spending every provider's quota at
            // once, and so the editor can explain a provider it cannot offer.
            // Names and reasons only — the credentials behind them never leave
            // the server.
            'metadataProviders' => $metadataProviders->statusFor($user),
        ]);
    }
}
