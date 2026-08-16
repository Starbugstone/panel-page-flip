<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\ShareClaimCode;
use App\Enum\ShareCodeType;
use App\Service\ShareClaimCodeService;
use App\Service\ShareContentCodeLifetime;
use App\Service\SharingCodeFormat;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Codes an owner hands out to give comics away without knowing an address.
 *
 * Two surfaces over one lifecycle. `/comic-codes` mints and redeems `C-` codes,
 * which carry exactly one comic; `/group-codes` does the same for `G-` codes,
 * which carry a package of two to twenty. Routing them separately is what makes
 * the invariant a property of the endpoint rather than a field in a body that a
 * client could get wrong — and the type on the way in is checked against the
 * type on the code regardless, because a prefix in a request body is still
 * something somebody typed.
 *
 * Every route here requires a signed-in account, including redemption. A code
 * is an offer, not a key: the share it produces is the ordinary kind, answered
 * and revoked the ordinary way.
 */
#[Route('/api/shares')]
final class ShareClaimCodeController extends AbstractController
{
    use RequiresAuthenticatedUser;

    public function __construct(
        private readonly ShareClaimCodeService $contentCodes,
        private readonly ShareContentCodeLifetime $lifetime,
    ) {
    }

    /**
     * The codes this owner has handed out, without the codes themselves.
     *
     * Both kinds in one list, each labelled with its type: an owner asking
     * "what have I given away?" wants one answer, and the prefix is what tells
     * a single comic from an arc at a glance.
     *
     * Dead ones stay in the list until the cleanup removes them a month after
     * they expire, because "how many people took that one up?" is a question
     * the owner asks after a code has stopped working, not while it still does.
     */
    #[Route('/content-codes', name: 'app_share_content_codes_list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        $user = $this->requireUser();

        return $this->json([
            'codes' => array_map(
                static fn (ShareClaimCode $code): array => $code->toOwnerPayload(),
                $this->contentCodes->codesFor($user)
            ),
            'maxUses' => ShareClaimCode::MAX_USES,
            'minUses' => ShareClaimCode::MIN_USES,
            'minGroupComics' => ShareClaimCode::MIN_GROUP_COMICS,
            'maxGroupComics' => ShareClaimCode::MAX_GROUP_COMICS,
            // So the dialog can say how long a code it has not created yet will
            // last. Every expiry it *renders* comes from the code's own
            // `expiresAt`; this is only for the sentence before that exists.
            'lifetimeDays' => $this->lifetime->days(),
        ]);
    }

    #[Route('/comic-codes', name: 'app_share_comic_codes_create', methods: ['POST'])]
    public function createComicCode(Request $request): JsonResponse
    {
        return $this->create($request, ShareCodeType::COMIC);
    }

    #[Route('/group-codes', name: 'app_share_group_codes_create', methods: ['POST'])]
    public function createGroupCode(Request $request): JsonResponse
    {
        return $this->create($request, ShareCodeType::GROUP);
    }

    #[Route('/content-codes/{id}', name: 'app_share_content_codes_revoke', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function revoke(int $id): JsonResponse
    {
        $user = $this->requireUser();

        $this->contentCodes->revoke($id, $user);

        return $this->json(['message' => 'Sharing code withdrawn.']);
    }

    /**
     * Claim the comics behind a code.
     *
     * One route for both kinds, because the person pasting a code has one field
     * and should not have to know which endpoint their code belongs to — the
     * prefix already says. The service refuses a `U-` code here with guidance
     * rather than a failure, since that is a real code in the wrong box.
     *
     * Authenticated, because a code grants nothing on its own and the shares it
     * creates have to belong to somebody.
     */
    #[Route('/content-codes/redeem', name: 'app_share_content_codes_redeem', methods: ['POST'], priority: 1)]
    public function redeem(Request $request): JsonResponse
    {
        $user = $this->requireUser();

        $data = \App\Http\JsonRequestDecoder::decode($request);
        $code = is_array($data) ? ($data['code'] ?? null) : null;

        if (!is_string($code)) {
            return $this->json(['message' => 'A sharing code is required.'], Response::HTTP_BAD_REQUEST);
        }

        return $this->json($this->contentCodes->redeem($code, $user));
    }

    private function create(Request $request, ShareCodeType $type): JsonResponse
    {
        $user = $this->requireUser();

        $data = \App\Http\JsonRequestDecoder::decode($request);
        if (!is_array($data)) {
            return $this->json(['message' => 'Invalid request body.'], Response::HTTP_BAD_REQUEST);
        }

        $rawComicIds = $data['comicIds'] ?? null;
        if (!is_array($rawComicIds) || $rawComicIds === []) {
            return $this->json(['message' => 'Select at least one comic to share.'], Response::HTTP_BAD_REQUEST);
        }
        // Refused on the raw count, before a single id is parsed or looked up.
        // Checking after the loop would mean a request carrying thousands of
        // ids did thousands of lookups on its way to a 400.
        if (count($rawComicIds) > ShareClaimCode::MAX_GROUP_COMICS) {
            return $this->json([
                'message' => ShareClaimCode::describeComicCountProblem($type),
            ], Response::HTTP_BAD_REQUEST);
        }

        $comicIds = ComicIdList::parse($rawComicIds);
        if ($comicIds === null) {
            return $this->json(['message' => 'Comic ids must be positive integers.'], Response::HTTP_BAD_REQUEST);
        }

        $rawUses = $data['maxUses'] ?? null;
        if (!is_int($rawUses) && !(is_string($rawUses) && ctype_digit($rawUses))) {
            return $this->json(['message' => 'Choose how many people may use the code.'], Response::HTTP_BAD_REQUEST);
        }

        ['code' => $code, 'plaintext' => $plaintext] = $this->contentCodes->issue(
            $comicIds,
            $user,
            $type,
            (int) $rawUses,
            // Strictly true, the same rule the emailed invitation applies:
            // a missing key or a truthy string is not somebody having read
            // the notice and ticked the box.
            ($data['senderResponsibilityAccepted'] ?? null) === true
        );

        return $this->json([
            'message' => 'Sharing code created.',
            // Returned once and never again — only the hash is stored, exactly
            // as for an invitation link. An owner who loses it makes a new one.
            'code' => SharingCodeFormat::forDisplay($type, $plaintext),
            'contentCode' => $code->toOwnerPayload(),
        ], Response::HTTP_CREATED);
    }
}
