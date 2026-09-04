<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\AdminTableFilterSuggestions;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/admin/table-filter-suggestions')]
#[IsGranted('ROLE_ADMIN')]
final class AdminTableFilterSuggestionController extends AbstractController
{
    #[Route('/{table}/{column}', name: 'app_admin_table_filter_suggestions', methods: ['GET'])]
    public function __invoke(
        string $table,
        string $column,
        Request $request,
        AdminTableFilterSuggestions $suggestions,
    ): JsonResponse {
        $source = $table . '/' . $column;
        if (!$suggestions->supports($source)) {
            throw $this->createNotFoundException('Unknown admin table filter.');
        }

        return $this->json([
            'suggestions' => $suggestions->search($source, (string) $request->query->get('query', '')),
        ]);
    }
}
