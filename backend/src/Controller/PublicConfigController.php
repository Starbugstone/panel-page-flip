<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\AdvertisingConfiguration;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Runtime configuration for pages nobody has signed in to yet.
 *
 * Separate from `/api/config`, which requires an account and answers questions
 * about that account. The landing and login pages need to know whether this
 * installation shows advertising before there is a user to ask about, and
 * widening the authenticated endpoint to cover them would mean either exposing
 * the rest of it publicly or teaching it to answer differently for nobody.
 *
 * Everything returned here is public by construction.
 */
final class PublicConfigController extends AbstractController
{
    public function __construct(private readonly AdvertisingConfiguration $advertising)
    {
    }

    #[Route('/api/public-config', name: 'api_public_config', methods: ['GET'])]
    public function __invoke(): JsonResponse
    {
        return $this->json(['adsense' => $this->advertising->publicConfiguration()]);
    }
}
