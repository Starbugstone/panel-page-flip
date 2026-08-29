<?php

declare(strict_types=1);

namespace App\Controller;

use App\Http\JsonRequestDecoder;
use App\Service\BulkUploadSessionService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The current batch of bulk upload, as the server sees it.
 *
 * Deliberately not a permission check on uploading. The upload endpoints do not
 * consult this and must not start to: bulk upload is a feature of the
 * application, and an installation with no advertising, a blocked script or no
 * rewarded inventory has to reach it exactly as before. What a session decides
 * is whether the *offer* is made again, which is a question about one batch.
 */
#[Route('/api/upload/bulk/session', name: 'api_bulk_upload_session_')]
final class BulkUploadSessionController extends AbstractController
{
    use RequiresAuthenticatedUser;

    public function __construct(private readonly BulkUploadSessionService $sessions)
    {
    }

    #[Route('', name: 'get', methods: ['GET'])]
    public function current(): JsonResponse
    {
        return $this->json($this->sessions->describe($this->requireUser()));
    }

    #[Route('', name: 'open', methods: ['POST'])]
    public function open(Request $request): JsonResponse
    {
        // What the browser reports about the rewarded advertisement, kept as a
        // note on the session rather than trusted as proof of anything — Google's
        // Offerwall has no server-side completion signal to check it against.
        $rewarded = (JsonRequestDecoder::decode($request)['rewarded'] ?? null) === true;

        return $this->json($this->sessions->open($this->requireUser(), $rewarded));
    }

    /** The batch is over. Answers with the state that leaves, not an empty 204. */
    #[Route('', name: 'close', methods: ['DELETE'])]
    public function close(): JsonResponse
    {
        $user = $this->requireUser();
        $this->sessions->close($user);

        return $this->json($this->sessions->describe($user));
    }
}
