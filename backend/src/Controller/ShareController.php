<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Comic;
use App\Entity\ComicShare;
use App\Entity\User;
use App\Repository\ComicShareRepository;
use App\Security\Voter\ComicVoter;
use App\Service\ComicSerializer;
use App\Service\ComicShareSerializer;
use App\Service\ComicShareService;
use App\Service\ShareException;
use Doctrine\ORM\EntityManagerInterface;
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
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /** Comics this user has shared, grouped by comic. */
    #[Route('/shared-by-me', name: 'app_shares_by_me', methods: ['GET'])]
    public function sharedByMe(): JsonResponse
    {
        $user = $this->requireUser();

        // Only shares on comics the owner still has: a deleted comic leaves this
        // list entirely, and the tombstone it leaves behind belongs to the
        // recipients who lost access, not to the person who removed it.
        $shares = $this->shareRepository->findAllForOwner($user);

        // Grouped server-side so the page does not have to reconstruct which
        // recipients belong to which comic.
        $groups = [];
        foreach ($shares as $share) {
            $comic = $share->getComic();
            if ($comic === null) {
                continue;
            }

            $groups[$comic->getId()] ??= [
                'comicId' => $comic->getId(),
                'title' => $comic->getTitle(),
                'author' => $comic->getAuthor(),
                'coverImagePath' => $this->comicSerializer->coverUrl($comic),
                'explicitContent' => $comic->isExplicitContent(),
                'recipients' => [],
            ];

            $groups[$comic->getId()]['recipients'][] = $this->shareSerializer->forOwner($share);
        }

        return $this->json(['sharedByMe' => array_values($groups)]);
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

    #[Route('/comics/{comicId}', name: 'app_shares_stop_all', methods: ['DELETE'], requirements: ['comicId' => '\d+'])]
    public function stopSharing(int $comicId): JsonResponse
    {
        $user = $this->requireUser();

        $comic = $this->entityManager->getRepository(Comic::class)->find($comicId);
        if (!$comic) {
            return $this->json(['message' => 'Comic not found.'], Response::HTTP_NOT_FOUND);
        }

        if (!$this->isGranted(ComicVoter::SHARE, $comic)) {
            return $this->json(['message' => 'You can only manage comics you own.'], Response::HTTP_FORBIDDEN);
        }

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
        $redact = $share->isExplicitContent()
            && !($isForCurrentUser && $share->getAdultConfirmedAt() !== null);

        return $this->json([
            'invitation' => [
                'comicTitle' => $redact ? null : ($comic?->getTitle() ?? $share->getComicTitleSnapshot()),
                'comicAuthor' => $redact ? null : ($comic?->getAuthor() ?? $share->getComicAuthorSnapshot()),
                'pageCount' => $redact ? null : $comic?->getPageCount(),
                'ownerName' => $share->getOwnerNameSnapshot(),
                // The intended recipient learns nothing from this — it is their
                // own address — so withholding it costs them nothing and stops
                // the link from disclosing who was invited.
                'recipientEmail' => $isForCurrentUser ? $share->getRecipientEmailNormalized() : null,
                'expiresAt' => $invitation->getExpiresAt()->format('c'),
                'coverImagePath' => $comic && $isForCurrentUser && !$redact
                    ? $this->comicSerializer->coverUrl($comic)
                    : null,
                'isForCurrentUser' => $isForCurrentUser,
                'explicitContent' => $share->isExplicitContent(),
                // Phrased for whoever is asking: "you must confirm to see this".
                'requiresAdultConfirmation' => $redact,
                // Withheld from everyone else, because whether the invited
                // person has declared their age is a fact about them, and a
                // link holder is not entitled to it.
                'adultConfirmed' => $isForCurrentUser && $share->getAdultConfirmedAt() !== null,
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
        $share = $this->shareService->accept($invitation, $user);

        return $this->json([
            'message' => 'Comic added to your collection.',
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
        $share = $this->findReceivedShare($id, $user);
        if ($share instanceof JsonResponse) {
            return $share;
        }

        $this->shareService->acceptShare($share, $user);

        return $this->json([
            'message' => 'Comic added to your collection.',
            'share' => $this->shareSerializer->forRecipient($share),
        ]);
    }

    #[Route('/{id}/decline', name: 'app_shares_decline', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function declineShare(int $id, #[CurrentUser] ?User $user): JsonResponse
    {
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
