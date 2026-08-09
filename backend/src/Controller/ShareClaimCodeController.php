<?php

namespace App\Controller;

use App\Entity\ShareClaimCode;
use App\Entity\User;
use App\Service\ShareClaimCodeService;
use App\Service\ShareException;
use App\Service\SharingCodeFormat;
use App\Service\SharingWorkflowService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Codes an owner hands out to give comics away without knowing an address.
 *
 * Every route here requires a signed-in account, including redemption. A code
 * is an offer, not a key: it says who may be invited, and the invitation it
 * produces is the ordinary kind, answered and revoked the ordinary way.
 */
#[Route('/api/shares/claim-codes')]
final class ShareClaimCodeController extends AbstractController
{
    public function __construct(private readonly ShareClaimCodeService $claimCodes)
    {
    }

    /** The codes this owner still has out, without the codes themselves. */
    #[Route('', name: 'app_share_claim_codes_list', methods: ['GET'])]
    public function list(#[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user) {
            return $this->json(['message' => 'Not authenticated.'], Response::HTTP_UNAUTHORIZED);
        }

        return $this->json([
            'codes' => array_map(
                static fn (ShareClaimCode $code): array => $code->toOwnerPayload(),
                $this->claimCodes->liveCodesFor($user)
            ),
            'maxUses' => ShareClaimCode::MAX_USES,
            'minUses' => ShareClaimCode::MIN_USES,
        ]);
    }

    #[Route('', name: 'app_share_claim_codes_create', methods: ['POST'])]
    public function create(Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user) {
            return $this->json(['message' => 'Not authenticated.'], Response::HTTP_UNAUTHORIZED);
        }

        $data = json_decode($request->getContent(), true);
        if (!is_array($data)) {
            return $this->json(['message' => 'Invalid request body.'], Response::HTTP_BAD_REQUEST);
        }

        $rawComicIds = $data['comicIds'] ?? null;
        if (!is_array($rawComicIds) || $rawComicIds === []) {
            return $this->json(['message' => 'Select at least one comic to share.'], Response::HTTP_BAD_REQUEST);
        }
        // Refused on the raw count, before a single id is parsed or looked up,
        // the same way the bulk invitation endpoint does it. Checking after the
        // loop would mean a request carrying thousands of ids did thousands of
        // lookups on its way to a 400.
        if (count($rawComicIds) > SharingWorkflowService::MAX_BULK_COMICS) {
            return $this->json([
                'message' => sprintf(
                    'A code can carry at most %d comics.',
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

        $rawUses = $data['maxUses'] ?? null;
        if (!is_int($rawUses) && !(is_string($rawUses) && ctype_digit($rawUses))) {
            return $this->json(['message' => 'Choose how many times the code may be used.'], Response::HTTP_BAD_REQUEST);
        }

        try {
            ['code' => $code, 'plaintext' => $plaintext] = $this->claimCodes->issue(
                $comicIds,
                $user,
                (int) $rawUses,
                // Strictly true, the same rule the emailed invitation applies:
                // a missing key or a truthy string is not somebody having read
                // the notice and ticked the box.
                ($data['senderResponsibilityAccepted'] ?? null) === true
            );
        } catch (ShareException $exception) {
            return $this->json($exception->toPayload(), $exception->getStatusCode());
        }

        return $this->json([
            'message' => 'Sharing code created.',
            // Returned once and never again — only the hash is stored, exactly
            // as for an invitation link. An owner who loses it makes a new one.
            'code' => SharingCodeFormat::forDisplay($plaintext),
            'claimCode' => $code->toOwnerPayload(),
        ], Response::HTTP_CREATED);
    }

    #[Route('/{id}', name: 'app_share_claim_codes_revoke', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function revoke(int $id, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user) {
            return $this->json(['message' => 'Not authenticated.'], Response::HTTP_UNAUTHORIZED);
        }

        try {
            $this->claimCodes->revoke($id, $user);
        } catch (ShareException $exception) {
            return $this->json($exception->toPayload(), $exception->getStatusCode());
        }

        return $this->json(['message' => 'Sharing code withdrawn.']);
    }

    /**
     * Claim the comics behind a code.
     *
     * Authenticated, because a code grants nothing on its own and the shares it
     * creates have to belong to somebody.
     */
    #[Route('/redeem', name: 'app_share_claim_codes_redeem', methods: ['POST'], priority: 1)]
    public function redeem(Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user) {
            return $this->json(['message' => 'Not authenticated.'], Response::HTTP_UNAUTHORIZED);
        }

        $data = json_decode($request->getContent(), true);
        $code = is_array($data) ? ($data['code'] ?? null) : null;

        if (!is_string($code)) {
            return $this->json(['message' => 'A sharing code is required.'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $result = $this->claimCodes->redeem($code, $user);
        } catch (ShareException $exception) {
            return $this->json($exception->toPayload(), $exception->getStatusCode());
        }

        return $this->json($result);
    }
}
