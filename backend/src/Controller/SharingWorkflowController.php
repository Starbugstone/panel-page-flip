<?php

namespace App\Controller;

use App\Entity\ComicShare;
use App\Entity\User;
use App\Service\ShareException;
use App\Service\SharingWorkflowService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/api/shares')]
final class SharingWorkflowController extends AbstractController
{
    public function __construct(private readonly SharingWorkflowService $workflow)
    {
    }

    #[Route('/recent-recipients', name: 'app_shares_recent_recipients', methods: ['GET'])]
    public function recentRecipients(#[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user) {
            return $this->json(['message' => 'Not authenticated.'], Response::HTTP_UNAUTHORIZED);
        }

        return $this->json([
            'recipients' => $this->workflow->recentRecipients($user),
        ]);
    }

    #[Route('/invitations/bulk', name: 'app_shares_bulk_invite', methods: ['POST'])]
    public function bulkInvite(Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user) {
            return $this->json(['message' => 'Not authenticated.'], Response::HTTP_UNAUTHORIZED);
        }

        $data = json_decode($request->getContent(), true);
        if (!is_array($data)) {
            return $this->json(['message' => 'Invalid request body.'], Response::HTTP_BAD_REQUEST);
        }

        if (($data['senderResponsibilityAccepted'] ?? null) !== true) {
            return $this->json([
                'message' => 'You must acknowledge responsibility for the content you share.',
            ], Response::HTTP_BAD_REQUEST);
        }

        $email = $data['email'] ?? null;
        if (!is_string($email)) {
            return $this->json(['message' => 'A recipient email address is required.'], Response::HTTP_BAD_REQUEST);
        }
        $email = ComicShare::normaliseEmail($email);
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->json(['message' => 'A valid recipient email address is required.'], Response::HTTP_BAD_REQUEST);
        }

        $rawComicIds = $data['comicIds'] ?? null;
        if (!is_array($rawComicIds) || $rawComicIds === []) {
            return $this->json(['message' => 'Select at least one comic to share.'], Response::HTTP_BAD_REQUEST);
        }
        if (count($rawComicIds) > SharingWorkflowService::MAX_BULK_COMICS) {
            return $this->json([
                'message' => sprintf(
                    'You can share at most %d comics in one action.',
                    SharingWorkflowService::MAX_BULK_COMICS
                ),
            ], Response::HTTP_BAD_REQUEST);
        }

        $comicIds = [];
        foreach ($rawComicIds as $rawComicId) {
            if (is_int($rawComicId)) {
                $comicId = $rawComicId;
            } elseif (is_string($rawComicId) && ctype_digit($rawComicId)) {
                $comicId = (int) $rawComicId;
            } else {
                return $this->json(['message' => 'Comic ids must be positive integers.'], Response::HTTP_BAD_REQUEST);
            }

            if ($comicId <= 0) {
                return $this->json(['message' => 'Comic ids must be positive integers.'], Response::HTTP_BAD_REQUEST);
            }
            $comicIds[] = $comicId;
        }

        try {
            $result = $this->workflow->inviteMany(
                $comicIds,
                $user,
                $email,
                true
            );
        } catch (ShareException $exception) {
            // The whole batch was refused before anything was created — an
            // exhausted invitation allowance, or a recipient the sender may not
            // invite. Reported as one failure with its real status rather than
            // as a per-comic result, because nothing was attempted.
            return $this->json($exception->toPayload(), $exception->getStatusCode());
        }

        $status = $result['created'] === $result['total']
            ? Response::HTTP_CREATED
            : Response::HTTP_MULTI_STATUS;

        return $this->json($result, $status);
    }
}
