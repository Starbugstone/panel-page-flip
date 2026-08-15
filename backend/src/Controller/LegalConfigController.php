<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final class LegalConfigController extends AbstractController
{
    public function __construct(
        #[Autowire('%privacy_operator%')]
        private readonly string $privacyOperator,
        #[Autowire('%privacy_email%')]
        private readonly string $privacyEmail,
        #[Autowire('%legal_email%')]
        private readonly string $legalEmail,
    ) {
    }

    #[Route('/api/legal-config', name: 'api_legal_config', methods: ['GET'])]
    public function __invoke(): JsonResponse
    {
        return $this->json([
            'operator' => $this->privacyOperator,
            'privacyEmail' => $this->privacyEmail,
            'legalEmail' => $this->legalEmail,
        ]);
    }
}
