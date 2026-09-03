<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\ComicShare;
use App\Entity\User;
use App\Repository\ComicShareRepository;
use App\Security\ComicAccess;
use App\Security\Voter\ComicVoter;
use App\Service\ComicSerializer;
use App\Service\ComicShareSerializer;
use App\Service\ComicShareService;
use App\Service\Pagination\PaginationRequest;
use App\Service\ShareException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Management surface for the sharing relationships behind /sharing.
 *
 * Reading a shared comic does not come through here: an accepted share puts the
 * comic in the recipient's normal collection, and every byte of it is served by
 * ComicController under the same COMIC_VIEW check the owner passes.
 */
#[Route('/api/shares')]
class ShareController extends AbstractController
{
    use RequiresAuthenticatedUser;

    public function __construct(
        private readonly ComicShareRepository $shareRepository,
        private readonly ComicShareService $shareService,
        private readonly ComicShareSerializer $shareSerializer,
        private readonly ComicSerializer $comicSerializer,
        private readonly ComicAccess $comicAccess,
    ) {
    }

    /** One database-backed page of grants this user has handed out. */
    #[Route('/shared-by-me', name: 'app_shares_by_me', methods: ['GET'])]
    public function sharedByMe(Request $request): JsonResponse
    {
        $user = $this->requireUser();

        $pagination = PaginationRequest::fromRequest($request, ComicShareRepository::OWNER_SORT_FIELDS, 'createdAt');

        // Only shares on comics the owner still has: a deleted comic leaves this
        // list entirely, and the tombstone it leaves behind belongs to the
        // recipients who lost access, not to the person who removed it.
        $page = $this->shareRepository->findOwnerPage($user, $pagination, [
            'comic' => $request->query->get('filterComic'),
            'recipient' => $request->query->get('filterRecipient'),
            'status' => $request->query->get('filterStatus'),
            'createdAt' => $request->query->get('filterCreatedAt'),
            'timezone' => $request->query->get('filterTimezone'),
        ]);

        return $this->json([
            'sharedByMe' => array_map(
                fn (ComicShare $share): array => $this->shareSerializer->forOwner($share),
                $page->items
            ),
            'pagination' => $page->toArray(),
        ]);
    }

    /** Invitations, accepted shares and tombstones addressed to this user. */
    #[Route('/shared-with-me', name: 'app_shares_with_me', methods: ['GET'])]
    public function sharedWithMe(): JsonResponse
    {
        $user = $this->requireUser();

        $shares = $this->shareRepository->findAllForRecipient($user);

        return $this->json([
            'sharedWithMe' => $this->shareSerializer->serializeManyForRecipient($shares),
        ]);
    }

    /** Counts for the navigation badge and the dashboard alert. */
    #[Route('/summary', name: 'app_shares_summary', methods: ['GET'])]
    public function summary(): JsonResponse
    {
        $user = $this->requireUser();

        return $this->json([
            'pendingInvitations' => $this->shareRepository->countPendingForRecipient($user),
            'deadShares' => $this->shareRepository->countDeadSharesForRecipient($user),
        ]);
    }

    #[Route('/{id}/resend', name: 'app_shares_resend', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function resend(int $id, #[CurrentUser] ?User $user): JsonResponse
    {
        $share = $this->findOwnedShare($id, $user);
        if ($share instanceof JsonResponse) {
            return $share;
        }

        $this->shareService->resend($share);

        // The link is not handed back. It is a bearer capability belonging to
        // the recipient, and the rest of sharing now mints it as the email is
        // written precisely so that it exists in one place — returning it here
        // would put it in a response body, a browser history and any proxy log
        // on the way, for the one path that used to be the exception.
        return $this->json([
            'message' => 'Invitation resent.',
            'share' => $this->shareSerializer->forOwner($share),
        ]);
    }

    #[Route('/{id}/revoke', name: 'app_shares_revoke', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function revoke(int $id, #[CurrentUser] ?User $user): JsonResponse
    {
        $share = $this->findOwnedShare($id, $user);
        if ($share instanceof JsonResponse) {
            return $share;
        }

        $this->shareService->revoke($share);

        return $this->json([
            'message' => 'Access revoked.',
            'share' => $this->shareSerializer->forOwner($share),
        ]);
    }

    /**
     * Delete the record of a finished share from the owner's list.
     *
     * Only for relationships that are already over — revoked, declined, or an
     * invitation that lapsed unanswered. A live share is refused with a 409
     * rather than revoked on the way out, so deleting can never be a quieter
     * way of cutting somebody off.
     */
    #[Route('/{id}', name: 'app_shares_delete', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function deleteShare(int $id, #[CurrentUser] ?User $user): JsonResponse
    {
        $share = $this->findOwnedShare($id, $user);
        if ($share instanceof JsonResponse) {
            return $share;
        }

        $this->shareService->deleteForOwner($share);

        return $this->json(['message' => 'Share record deleted.']);
    }

    #[Route('/comics/{comicId}', name: 'app_shares_stop_all', methods: ['DELETE'], requirements: ['comicId' => '\d+'])]
    public function stopSharing(int $comicId): JsonResponse
    {
        $this->requireUser();

        // The last route that still found a comic and judged it by hand, and it
        // had the split the rest of the API has stopped making: 404 for missing,
        // 403 for somebody else's. Between them that is an existence oracle, so
        // it goes through the same guard as everywhere else — a stranger is told
        // only that there is no such comic, and an owner who may not share this
        // one is still refused in as many words.
        $comic = $this->comicAccess->requireComic($comicId, ComicVoter::SHARE);

        $revoked = $this->shareService->stopSharing($comic);

        return $this->json([
            'message' => sprintf('Sharing stopped for %d recipient(s).', $revoked),
            'revoked' => $revoked,
        ]);
    }

    /**
     * Invitation preview.
     *
     * Safe by design: mail scanners and link-preview bots follow links without a
     * person behind them, so this only ever reads. Accepting and declining are
     * separate POSTs that a button press has to trigger.
     */
    #[Route('/invitations/{token}', name: 'app_shares_invitation_preview', methods: ['GET'])]
    public function previewInvitation(string $token, #[CurrentUser] ?User $user): JsonResponse
    {
        $invitation = $this->shareService->resolveInvitation($token);

        $share = $invitation->getComicShare();
        $comic = $share->getComic();
        $members = $this->shareService->invitationMembers($share);
        $isFolderBatch = $share->getInvitationBatchId() !== null;
        // This endpoint is public, so anything it returns is readable by anyone
        // holding the link — a forwarded email, a link-preview bot, a proxy log.
        // Only what the invitation is *about* goes out unconditionally; the
        // cover and the recipient's own address are gated on identity.
        $isForCurrentUser = $this->isRecipient($share, $user);

        // An age declaration is made by one person about themselves. It is not
        // a property of the link, so it cannot unlock the link.
        //
        // Both halves are needed. Confirming is what opens the gate, but only
        // for whoever confirmed: this endpoint is public, and a forwarded email,
        // a scanner or a proxy log holds the same token the recipient does. So
        // an explicit invitation stays shut for everybody the server cannot
        // identify as the recipient, however many times the recipient has
        // confirmed.
        $explicitMembers = array_values(array_filter(
            $members,
            static fn (ComicShare $member): bool => $member->isExplicitContent()
        ));
        $adultConfirmed = $explicitMembers === [] || array_reduce(
            $explicitMembers,
            static fn (bool $confirmed, ComicShare $member): bool => $confirmed && $member->getAdultConfirmedAt() !== null,
            true
        );
        $redact = $explicitMembers !== [] && !($isForCurrentUser && $adultConfirmed);

        return $this->json([
            'invitation' => [
                'comicTitle' => $redact || $isFolderBatch ? null : ($comic?->getTitle() ?? $share->getComicTitleSnapshot()),
                'comicAuthor' => $redact || $isFolderBatch ? null : ($comic?->getAuthor() ?? $share->getComicAuthorSnapshot()),
                'pageCount' => $redact || $isFolderBatch ? null : $comic?->getPageCount(),
                'isFolderBatch' => $isFolderBatch,
                'folderName' => $share->getInvitationBatchName(),
                'comicCount' => count($members),
                'ownerName' => $share->getOwnerNameSnapshot(),
                // The intended recipient learns nothing from this — it is their
                // own address — so withholding it costs them nothing and stops
                // the link from disclosing who was invited.
                'recipientEmail' => $isForCurrentUser ? $share->getRecipientEmailNormalized() : null,
                'expiresAt' => $invitation->getExpiresAt()->format('c'),
                'coverImagePath' => $comic && $isForCurrentUser && !$redact && !$isFolderBatch
                    ? $this->comicSerializer->coverUrl($comic)
                    : null,
                'isForCurrentUser' => $isForCurrentUser,
                'explicitContent' => $explicitMembers !== [],
                // Phrased for whoever is asking: "you must confirm to see this".
                'requiresAdultConfirmation' => $redact,
                // Withheld from everyone else, because whether the invited
                // person has declared their age is a fact about them, and a
                // link holder is not entitled to it.
                'adultConfirmed' => $isForCurrentUser && $adultConfirmed,
            ],
        ]);
    }

    /**
     * Record the recipient's declaration that they are 18 or older, from an
     * emailed invitation link.
     *
     * A POST because it writes, and because a GET would be followed by the same
     * mail scanners the preview is careful about — making an age declaration
     * something a link preview could make on somebody's behalf.
     */
    #[Route('/invitations/{token}/confirm-adult', name: 'app_shares_invitation_confirm_adult', methods: ['POST'])]
    public function confirmAdultForInvitation(string $token, Request $request): JsonResponse
    {
        $user = $this->requireUser();

        if (!$this->isAdultConfirmed($request)) {
            return $this->json([
                'message' => 'You must confirm that you are 18 or older to access this shared comic.',
                'code' => ShareException::CODE_ADULT_CONFIRMATION_REQUIRED,
            ], Response::HTTP_BAD_REQUEST);
        }

        $invitation = $this->shareService->resolveInvitation($token);
        $share = $this->shareService->confirmAdult($invitation->getComicShare(), $user);

        return $this->json([
            'message' => 'Age confirmed.',
            // Freshly serialized, so the caller gets the now-unlocked view back
            // from the same request that unlocked it.
            'share' => $this->shareSerializer->forRecipient($share),
        ]);
    }

    /** The same declaration, made from the Sharing page rather than a link. */
    #[Route('/{id}/confirm-adult', name: 'app_shares_confirm_adult', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function confirmAdultForShare(int $id, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        $user = $this->requireUser();
        $share = $this->findReceivedShare($id, $user);
        if ($share instanceof JsonResponse) {
            return $share;
        }

        if (!$this->isAdultConfirmed($request)) {
            return $this->json([
                'message' => 'You must confirm that you are 18 or older to access this shared comic.',
                'code' => ShareException::CODE_ADULT_CONFIRMATION_REQUIRED,
            ], Response::HTTP_BAD_REQUEST);
        }

        $this->shareService->confirmAdult($share, $user);

        return $this->json([
            'message' => 'Age confirmed.',
            'share' => $this->shareSerializer->forRecipient($share),
        ]);
    }

    /**
     * Whether the request carries the declaration itself.
     *
     * Only the boolean is read. The moment it was made is the server's to
     * record, because a timestamp the declaring party can choose is not
     * evidence of anything.
     */
    private function isAdultConfirmed(Request $request): bool
    {
        return (\App\Http\JsonRequestDecoder::decode($request)['adultConfirmed'] ?? null) === true;
    }

    #[Route('/invitations/{token}/accept', name: 'app_shares_invitation_accept', methods: ['POST'])]
    public function acceptInvitation(string $token): JsonResponse
    {
        $user = $this->requireUser();

        $invitation = $this->shareService->resolveInvitation($token);
        $acceptedCount = count(array_filter(
            $this->shareService->invitationMembers($invitation->getComicShare()),
            static fn (ComicShare $member): bool => $member->isPending()
        ));
        $share = $this->shareService->accept($invitation, $user);

        return $this->json([
            'message' => $acceptedCount === 1
                ? 'Comic added to your collection.'
                : sprintf('%d comics added to your collection.', $acceptedCount),
            'acceptedCount' => $acceptedCount,
            'share' => $this->shareSerializer->forRecipient($share),
        ]);
    }

    #[Route('/invitations/{token}/decline', name: 'app_shares_invitation_decline', methods: ['POST'])]
    public function declineInvitation(string $token): JsonResponse
    {
        $user = $this->requireUser();

        $invitation = $this->shareService->resolveInvitation($token);
        $this->shareService->decline($invitation, $user);

        return $this->json(['message' => 'Invitation declined.']);
    }

    /**
     * Answer an invitation from the Sharing page.
     *
     * The emailed link is not the only way in: a recipient who is signed in and
     * looking at their own invitation has identified themselves more strongly
     * than the token could, so no token is required here.
     */
    #[Route('/{id}/accept', name: 'app_shares_accept', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function acceptShare(int $id, #[CurrentUser] ?User $user): JsonResponse
    {
        $user = $this->requireUser();
        $share = $this->findReceivedShare($id, $user);
        if ($share instanceof JsonResponse) {
            return $share;
        }

        $acceptedCount = count(array_filter(
            $this->shareService->invitationMembers($share),
            static fn (ComicShare $member): bool => $member->isPending()
        ));
        $this->shareService->acceptShare($share, $user);

        return $this->json([
            'message' => $acceptedCount === 1
                ? 'Comic added to your collection.'
                : sprintf('%d comics added to your collection.', $acceptedCount),
            'acceptedCount' => $acceptedCount,
            'share' => $this->shareSerializer->forRecipient($share),
        ]);
    }

    #[Route('/{id}/decline', name: 'app_shares_decline', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function declineShare(int $id, #[CurrentUser] ?User $user): JsonResponse
    {
        $user = $this->requireUser();
        $share = $this->findReceivedShare($id, $user);
        if ($share instanceof JsonResponse) {
            return $share;
        }

        $this->shareService->declineShare($share, $user);

        return $this->json(['message' => 'Invitation declined.']);
    }

    /** Hide a shared comic from the recipient's collection, keeping the access. */
    #[Route('/{id}/remove', name: 'app_shares_remove', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function removeFromCollection(int $id, #[CurrentUser] ?User $user): JsonResponse
    {
        $share = $this->findReceivedShare($id, $user);
        if ($share instanceof JsonResponse) {
            return $share;
        }

        $this->shareService->removeFromCollection($share);

        return $this->json([
            'message' => 'Removed from your collection.',
            'share' => $this->shareSerializer->forRecipient($share),
        ]);
    }

    #[Route('/{id}/restore', name: 'app_shares_restore', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function restoreToCollection(int $id, #[CurrentUser] ?User $user): JsonResponse
    {
        $share = $this->findReceivedShare($id, $user);
        if ($share instanceof JsonResponse) {
            return $share;
        }

        $this->shareService->restoreToCollection($share);

        return $this->json([
            'message' => 'Restored to your collection.',
            'share' => $this->shareSerializer->forRecipient($share),
        ]);
    }

    /**
     * Clear dead share history: every tombstone by default, or the named
     * records only.
     *
     * Live shares are never in scope, so a pending invitation or a comic the
     * owner still shares cannot be swept away by mistake.
     */
    #[Route('/tombstones', name: 'app_shares_clear_tombstones', methods: ['DELETE'])]
    public function clearTombstones(Request $request): JsonResponse
    {
        $user = $this->requireUser();

        $data = \App\Http\JsonRequestDecoder::decode($request);
        $shareIds = null;

        if (array_key_exists('shareIds', $data)) {
            $shareIds = $this->normaliseShareIds($data['shareIds']);
            if ($shareIds === null) {
                return $this->json(['message' => 'Invalid shareIds.'], Response::HTTP_BAD_REQUEST);
            }
        }

        $removed = $this->shareService->clearDeadShares($user, $shareIds);

        return $this->json([
            'message' => sprintf('%d unavailable shared comic(s) removed.', $removed),
            'removed' => $removed,
        ]);
    }

    /** The share, or the response to return instead of acting on it. */
    private function findOwnedShare(int $id, ?User $user): ComicShare|JsonResponse
    {
        $user = $this->requireUser();

        $share = $this->shareRepository->find($id);
        // A share the caller does not own is reported as missing rather than
        // forbidden: confirming that an id exists would leak who shares what.
        if (!$share || $share->getOwner()?->getId() !== $user->getId()) {
            return $this->json(['message' => 'Share not found.'], Response::HTTP_NOT_FOUND);
        }

        return $share;
    }

    /** The share, or the response to return instead of acting on it. */
    private function findReceivedShare(int $id, ?User $user): ComicShare|JsonResponse
    {
        $user = $this->requireUser();

        $share = $this->shareRepository->find($id);
        if (!$share || !$this->isRecipient($share, $user)) {
            return $this->json(['message' => 'Share not found.'], Response::HTTP_NOT_FOUND);
        }

        return $share;
    }

    private function isRecipient(ComicShare $share, ?User $user): bool
    {
        if (!$user) {
            return false;
        }

        return $share->getRecipientUser()?->getId() === $user->getId()
            || $share->getRecipientEmailNormalized() === ComicShare::normaliseEmail((string) $user->getEmail());
    }

    /** @return list<int>|null */
    private function normaliseShareIds(mixed $shareIds): ?array
    {
        if ($shareIds === null) {
            return null;
        }

        if (!is_array($shareIds) || $shareIds === [] || count($shareIds) > 500) {
            return null;
        }

        $normalised = [];
        foreach ($shareIds as $shareId) {
            if (!is_int($shareId) && !(is_string($shareId) && ctype_digit($shareId))) {
                return null;
            }

            $shareId = (int) $shareId;
            if ($shareId <= 0) {
                return null;
            }
            $normalised[$shareId] = $shareId;
        }

        return array_values($normalised);
    }
}
