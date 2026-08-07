<?php

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
    public function sharedByMe(#[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user) {
            return $this->json(['message' => 'Not authenticated.'], Response::HTTP_UNAUTHORIZED);
        }

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
                'recipients' => [],
            ];

            $groups[$comic->getId()]['recipients'][] = $this->shareSerializer->forOwner($share);
        }

        return $this->json(['sharedByMe' => array_values($groups)]);
    }

    /** Invitations, accepted shares and tombstones addressed to this user. */
    #[Route('/shared-with-me', name: 'app_shares_with_me', methods: ['GET'])]
    public function sharedWithMe(#[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user) {
            return $this->json(['message' => 'Not authenticated.'], Response::HTTP_UNAUTHORIZED);
        }

        $shares = $this->shareRepository->findAllForRecipient($user);

        return $this->json([
            'sharedWithMe' => $this->shareSerializer->serializeManyForRecipient($shares),
        ]);
    }

    /** Counts for the navigation badge and the dashboard alert. */
    #[Route('/summary', name: 'app_shares_summary', methods: ['GET'])]
    public function summary(#[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user) {
            return $this->json(['message' => 'Not authenticated.'], Response::HTTP_UNAUTHORIZED);
        }

        return $this->json([
            'pendingInvitations' => $this->shareRepository->countPendingForRecipient($user),
            'deadShares' => $this->shareRepository->countDeadSharesForRecipient($user),
        ]);
    }

    #[Route('/comics/{comicId}/invitations', name: 'app_shares_invite', methods: ['POST'], requirements: ['comicId' => '\d+'])]
    public function invite(int $comicId, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user) {
            return $this->json(['message' => 'Not authenticated.'], Response::HTTP_UNAUTHORIZED);
        }

        $comic = $this->entityManager->getRepository(Comic::class)->find($comicId);
        if (!$comic) {
            return $this->json(['message' => 'Comic not found.'], Response::HTTP_NOT_FOUND);
        }

        if (!$this->isGranted(ComicVoter::SHARE, $comic)) {
            return $this->json(['message' => 'You can only share comics you own.'], Response::HTTP_FORBIDDEN);
        }

        $data = json_decode($request->getContent(), true);
        $email = is_array($data) ? ($data['email'] ?? null) : null;
        if (!is_string($email)) {
            return $this->json(['message' => 'A recipient email address is required.'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $invitation = $this->shareService->invite($comic, $user, $email);
        } catch (ShareException $exception) {
            return $this->json(['message' => $exception->getMessage()], $exception->getStatusCode());
        }

        return $this->json([
            'message' => 'Invitation sent.',
            'share' => $this->shareSerializer->forOwner($invitation->share),
            // Returned once and never again — only the hash is stored. It lets
            // the owner pass the link on themselves when the email does not
            // arrive, without the server being able to reproduce it later.
            'invitationUrl' => $invitation->invitationUrl,
        ], Response::HTTP_CREATED);
    }

    #[Route('/{id}/resend', name: 'app_shares_resend', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function resend(int $id, #[CurrentUser] ?User $user): JsonResponse
    {
        $share = $this->findOwnedShare($id, $user);
        if ($share instanceof JsonResponse) {
            return $share;
        }

        try {
            $invitation = $this->shareService->resend($share);
        } catch (ShareException $exception) {
            return $this->json(['message' => $exception->getMessage()], $exception->getStatusCode());
        }

        return $this->json([
            'message' => 'Invitation resent.',
            'share' => $this->shareSerializer->forOwner($share),
            'invitationUrl' => $invitation->invitationUrl,
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
    public function stopSharing(int $comicId, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user) {
            return $this->json(['message' => 'Not authenticated.'], Response::HTTP_UNAUTHORIZED);
        }

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
        try {
            $invitation = $this->shareService->resolveInvitation($token);
        } catch (ShareException $exception) {
            return $this->json(['message' => $exception->getMessage()], $exception->getStatusCode());
        }

        $share = $invitation->getComicShare();
        $comic = $share->getComic();
        // This endpoint is public, so anything it returns is readable by anyone
        // holding the link — a forwarded email, a link-preview bot, a proxy log.
        // Only what the invitation is *about* goes out unconditionally; the
        // cover and the recipient's own address are gated on identity.
        $isForCurrentUser = $this->isRecipient($share, $user);

        return $this->json([
            'invitation' => [
                'comicTitle' => $comic?->getTitle() ?? $share->getComicTitleSnapshot(),
                'comicAuthor' => $comic?->getAuthor() ?? $share->getComicAuthorSnapshot(),
                'pageCount' => $comic?->getPageCount(),
                'ownerName' => $share->getOwnerNameSnapshot(),
                // The intended recipient learns nothing from this — it is their
                // own address — so withholding it costs them nothing and stops
                // the link from disclosing who was invited.
                'recipientEmail' => $isForCurrentUser ? $share->getRecipientEmailNormalized() : null,
                'expiresAt' => $invitation->getExpiresAt()->format('c'),
                'coverImagePath' => $comic && $isForCurrentUser
                    ? $this->comicSerializer->coverUrl($comic)
                    : null,
                'isForCurrentUser' => $isForCurrentUser,
            ],
        ]);
    }

    #[Route('/invitations/{token}/accept', name: 'app_shares_invitation_accept', methods: ['POST'])]
    public function acceptInvitation(string $token, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user) {
            return $this->json(['message' => 'Not authenticated.'], Response::HTTP_UNAUTHORIZED);
        }

        try {
            $invitation = $this->shareService->resolveInvitation($token);
            $share = $this->shareService->accept($invitation, $user);
        } catch (ShareException $exception) {
            return $this->json(['message' => $exception->getMessage()], $exception->getStatusCode());
        }

        return $this->json([
            'message' => 'Comic added to your collection.',
            'share' => $this->shareSerializer->forRecipient($share),
        ]);
    }

    #[Route('/invitations/{token}/decline', name: 'app_shares_invitation_decline', methods: ['POST'])]
    public function declineInvitation(string $token, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user) {
            return $this->json(['message' => 'Not authenticated.'], Response::HTTP_UNAUTHORIZED);
        }

        try {
            $invitation = $this->shareService->resolveInvitation($token);
            $this->shareService->decline($invitation, $user);
        } catch (ShareException $exception) {
            return $this->json(['message' => $exception->getMessage()], $exception->getStatusCode());
        }

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

        try {
            $this->shareService->acceptShare($share, $user);
        } catch (ShareException $exception) {
            return $this->json(['message' => $exception->getMessage()], $exception->getStatusCode());
        }

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

        try {
            $this->shareService->declineShare($share, $user);
        } catch (ShareException $exception) {
            return $this->json(['message' => $exception->getMessage()], $exception->getStatusCode());
        }

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

        try {
            $this->shareService->removeFromCollection($share);
        } catch (ShareException $exception) {
            return $this->json(['message' => $exception->getMessage()], $exception->getStatusCode());
        }

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

        try {
            $this->shareService->restoreToCollection($share);
        } catch (ShareException $exception) {
            return $this->json(['message' => $exception->getMessage()], $exception->getStatusCode());
        }

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
    public function clearTombstones(Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user) {
            return $this->json(['message' => 'Not authenticated.'], Response::HTTP_UNAUTHORIZED);
        }

        $data = json_decode($request->getContent(), true);
        $shareIds = null;

        if (is_array($data) && array_key_exists('shareIds', $data)) {
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
        if (!$user) {
            return $this->json(['message' => 'Not authenticated.'], Response::HTTP_UNAUTHORIZED);
        }

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
        if (!$user) {
            return $this->json(['message' => 'Not authenticated.'], Response::HTTP_UNAUTHORIZED);
        }

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
