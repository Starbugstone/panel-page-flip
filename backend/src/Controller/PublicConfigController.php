<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\AdvertisingConfiguration;
use App\Service\ConsentConfiguration;
use App\Service\GoogleAnalyticsConfiguration;
use App\Service\TurnstileConfiguration;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
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
 *
 * One endpoint rather than one per subject. It used to sit beside
 * `/api/legal-config`, which meant the privacy and cookie pages made two
 * anonymous round trips on the same render for two halves of the same answer —
 * and every public fact added since would have been a third controller, a third
 * route and a third security rule.
 */
final class PublicConfigController extends AbstractController
{
    public function __construct(
        private readonly AdvertisingConfiguration $advertising,
        private readonly GoogleAnalyticsConfiguration $analytics,
        private readonly ConsentConfiguration $consent,
        private readonly TurnstileConfiguration $turnstile,
        #[Autowire('%privacy_operator%')]
        private readonly string $privacyOperator,
        #[Autowire('%privacy_email%')]
        private readonly string $privacyEmail,
        #[Autowire('%legal_email%')]
        private readonly string $legalEmail,
    ) {
    }

    #[Route('/api/public-config', name: 'api_public_config', methods: ['GET'])]
    public function __invoke(): JsonResponse
    {
        return $this->json([
            'adsense' => $this->advertising->publicConfiguration(),
            'analytics' => $this->analytics->publicConfiguration(),
            'consent' => $this->consent->publicConfiguration(),
            'turnstile' => $this->turnstile->publicConfiguration(),
            'operator' => $this->privacyOperator,
            'privacyEmail' => $this->privacyEmail,
            'legalEmail' => $this->legalEmail,
        ]);
    }
}
