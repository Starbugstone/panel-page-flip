<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\UserWarning;
use App\Service\UserWarningService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The notices waiting for the signed-in account, and dismissing them.
 *
 * Read on its own request rather than folded into `/api/me`, which the session
 * monitor polls: a warning is not news that arrives mid-session, and re-reading
 * one every thirty seconds would only give the banner a chance to come back
 * after it was dismissed.
 */
final class UserWarningController extends AbstractController
{
    use RequiresAuthenticatedUser;

    public function __construct(private readonly UserWarningService $warnings)
    {
    }

    #[Route('/api/me/warnings', name: 'api_me_warnings', methods: ['GET'])]
    public function open(): JsonResponse
    {
        $user = $this->requireUser();

        return $this->json([
            'warnings' => array_map(
                static fn (UserWarning $warning): array => $warning->toRecipientPayload(),
                $this->warnings->openFor($user)
            ),
        ]);
    }

    /**
     * Dismiss one.
     *
     * The record is kept and marked, never deleted: "were they told?" is a
     * question that gets asked after the second incident, not the first.
     */
    #[Route('/api/me/warnings/{id}/acknowledge', name: 'api_me_warnings_acknowledge', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function acknowledge(int $id): JsonResponse
    {
        $user = $this->requireUser();

        if (!$this->warnings->acknowledge($id, $user)) {
            // Missing rather than forbidden: somebody else's warning must not be
            // distinguishable from one that does not exist.
            return $this->json(['message' => 'That notice was not found.'], Response::HTTP_NOT_FOUND);
        }

        return $this->json(['message' => 'Notice dismissed.']);
    }
}
