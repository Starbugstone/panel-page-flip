<?php

namespace App\Service;

use App\Entity\Comic;
use App\Entity\ComicShare;
use App\Entity\ShareInvitationToken;
use App\Entity\User;
use App\Repository\ComicShareRepository;
use App\Repository\ShareInvitationTokenRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Twig\Environment;

/**
 * Everything that changes a sharing relationship.
 *
 * Sharing grants read access to the owner's single copy. Nothing here writes a
 * comic file, a cover or a Comic row: the whole feature is the state of a
 * {@see ComicShare} plus the tokens that let a recipient answer an invitation.
 */
class ComicShareService
{
    /** How long an unanswered invitation stays open. */
    public const INVITATION_TTL = '+7 days';

    /** Invitations one owner may send or resend per hour. */
    public const MAX_INVITATIONS_PER_HOUR = 10;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ComicShareRepository $shareRepository,
        private readonly ShareInvitationTokenRepository $tokenRepository,
        private readonly MailerInterface $mailer,
        private readonly Environment $twig,
        private readonly LoggerInterface $logger,
        #[Autowire('%frontend_url%')]
        private readonly string $frontendUrl,
        #[Autowire('%mailer_from_address%')]
        private readonly string $mailerFromAddress,
        #[Autowire('%mailer_from_name%')]
        private readonly string $mailerFromName,
    ) {
    }

    /**
     * Invite somebody to read a comic, or re-open an invitation that was
     * declined, revoked or left unanswered.
     *
     * The relationship and the email are one unit of work: a send that fails
     * rolls the invitation back, so the owner is never shown a recipient who
     * was never actually contacted.
     *
     * @throws ShareException
     */
    public function invite(Comic $comic, User $owner, string $recipientEmail): IssuedInvitation
    {
        $email = ComicShare::normaliseEmail($recipientEmail);

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new ShareException('A valid recipient email address is required.', 400);
        }

        if ($email === ComicShare::normaliseEmail((string) $owner->getEmail())) {
            throw new ShareException('You already own this comic.', 400);
        }

        $this->assertWithinInvitationRate($owner);

        $share = $this->shareRepository->findForComicAndRecipient($comic, $email);

        if ($share !== null && $share->getStatus() === ComicShare::STATUS_ACCEPTED && !$share->isTombstoned()) {
            throw new ShareException('This comic is already shared with that person.', 409);
        }

        if ($share !== null && $share->isPending()) {
            throw new ShareException(
                'An invitation is already pending for that person. Resend it instead.',
                409
            );
        }

        if ($share === null) {
            $share = new ComicShare($comic, $owner, $email);
            $this->entityManager->persist($share);
        } else {
            // A tombstoned row for this recipient can only exist if the comic
            // it pointed at is gone, so it never refers to the comic being
            // shared now; re-point it and let it live again.
            $share->setComic($comic)->setOwner($owner)->refreshSnapshots();
        }

        $share->markPending($this->invitationExpiry());

        return $this->issueInvitation($share, $comic, $owner);
    }

    /**
     * Send a fresh link for an invitation that is already pending, invalidating
     * the previous one.
     *
     * @throws ShareException
     */
    public function resend(ComicShare $share): IssuedInvitation
    {
        $comic = $share->getComic();
        $owner = $share->getOwner();

        if ($comic === null || $owner === null || $share->isTombstoned()) {
            throw new ShareException('This comic is no longer available to share.', 410);
        }

        if ($share->getStatus() === ComicShare::STATUS_ACCEPTED) {
            throw new ShareException('That invitation has already been accepted.', 409);
        }

        $this->assertWithinInvitationRate($owner);
        $share->markPending($this->invitationExpiry())->refreshSnapshots();

        return $this->issueInvitation($share, $comic, $owner);
    }

    /**
     * Resolve an invitation link without changing anything.
     *
     * Deliberately side-effect free: mail scanners and link previews follow
     * links on the recipient's behalf, so opening one must never be able to
     * accept or decline an invitation.
     *
     * @throws ShareException
     */
    public function resolveInvitation(string $plaintextToken): ShareInvitationToken
    {
        $token = $this->tokenRepository->findByPlaintext($plaintextToken);
        if ($token === null) {
            throw new ShareException('This invitation link is not valid.', 404);
        }

        $share = $token->getComicShare();

        if ($share->isTombstoned() || $share->getComic() === null) {
            throw new ShareException('The shared comic is no longer available.', 410);
        }

        if ($share->getStatus() === ComicShare::STATUS_REVOKED) {
            throw new ShareException('The owner has withdrawn this invitation.', 410);
        }

        if (!$token->isUsable()) {
            // An accepted share whose token has been spent is a success that
            // was simply opened twice, not a broken link.
            if ($token->getUsedAt() !== null && $share->getStatus() === ComicShare::STATUS_ACCEPTED) {
                throw new ShareException('You have already accepted this invitation.', 409);
            }

            throw new ShareException('This invitation link has expired.', 410);
        }

        return $token;
    }

    /**
     * @throws ShareException
     */
    public function accept(ShareInvitationToken $token, User $recipient): ComicShare
    {
        $token->markUsed();

        return $this->acceptShare($token->getComicShare(), $recipient);
    }

    /**
     * @throws ShareException
     */
    public function decline(ShareInvitationToken $token, User $recipient): ComicShare
    {
        $token->markUsed();

        return $this->declineShare($token->getComicShare(), $recipient);
    }

    /**
     * Answer an invitation the recipient found on their Sharing page rather
     * than in their inbox.
     *
     * No token is involved, and none is needed: the token exists to prove that
     * whoever opened an emailed link was sent it, and a signed-in recipient
     * looking at their own invitation has already proved more than that.
     *
     * @throws ShareException
     */
    public function acceptShare(ComicShare $share, User $recipient): ComicShare
    {
        $this->assertRecipient($share, $recipient);
        $this->assertAnswerable($share);

        $share->markAccepted($recipient)->refreshSnapshots();
        // Every link that was issued for this invitation is spent now.
        $this->revokeOutstandingTokens($share);
        $this->entityManager->flush();

        return $share;
    }

    /**
     * @throws ShareException
     */
    public function declineShare(ComicShare $share, User $recipient): ComicShare
    {
        $this->assertRecipient($share, $recipient);
        $this->assertAnswerable($share);

        $share->markDeclined($recipient);
        $this->revokeOutstandingTokens($share);
        $this->entityManager->flush();

        return $share;
    }

    /** Withdraw one recipient's access. Takes effect on the next request. */
    public function revoke(ComicShare $share): void
    {
        $share->refreshSnapshots()->markRevoked();
        $this->revokeOutstandingTokens($share);
        $this->entityManager->flush();
    }

    /**
     * Withdraw every live share on a comic.
     *
     * @return int the number of recipients affected
     */
    public function stopSharing(Comic $comic): int
    {
        $shares = $this->shareRepository->findLiveSharesForComic($comic);
        foreach ($shares as $share) {
            $share->refreshSnapshots()->markRevoked();
            $this->revokeOutstandingTokens($share);
        }
        $this->entityManager->flush();

        return count($shares);
    }

    /** Hide a shared comic from the recipient's collection without giving it up. */
    public function removeFromCollection(ComicShare $share): void
    {
        $share->markRecipientRemoved();
        $this->entityManager->flush();
    }

    /**
     * @throws ShareException
     */
    public function restoreToCollection(ComicShare $share): void
    {
        if (!$share->grantsAccess()) {
            throw new ShareException('This comic is no longer available to restore.', 410);
        }

        $share->markRestored();
        $this->entityManager->flush();
    }

    /**
     * Record why a comic vanished on every share that still pointed at it, and
     * cut the access those shares granted.
     *
     * Callers run this inside the transaction that deletes the comic, and do
     * not flush here: the tombstones and the deletion have to land together or
     * not at all.
     *
     * @return int the number of recipients who lose access
     */
    public function tombstoneSharesForComic(Comic $comic, string $reason): int
    {
        $shares = $this->shareRepository->findLiveSharesForComic($comic);
        $accepted = 0;

        foreach ($shares as $share) {
            if ($share->getStatus() === ComicShare::STATUS_ACCEPTED) {
                ++$accepted;
            }
            $this->revokeOutstandingTokens($share);
            $share->markUnavailable($reason);
        }

        return $accepted;
    }

    /** How many recipients a deletion would affect, for the owner's warning. */
    public function countLiveShares(Comic $comic): int
    {
        return count($this->shareRepository->findLiveSharesForComic($comic));
    }

    /**
     * Clear this recipient's dead share history — tombstones and relationships
     * that cannot come back on their own.
     *
     * Live entries are never touched, so a pending invitation or a comic the
     * owner still shares survives the sweep.
     *
     * @param list<int>|null $shareIds restrict to these records, or null for all
     * @return int the number of records removed
     */
    public function clearDeadShares(User $recipient, ?array $shareIds = null): int
    {
        $removed = 0;
        $wanted = $shareIds === null ? null : array_flip($shareIds);

        foreach ($this->shareRepository->findDeadSharesForRecipient($recipient) as $share) {
            if ($wanted !== null && !isset($wanted[(int) $share->getId()])) {
                continue;
            }

            $this->entityManager->remove($share);
            ++$removed;
        }

        if ($removed > 0) {
            $this->entityManager->flush();
        }

        return $removed;
    }

    /**
     * Mint a token, persist the relationship and email the link.
     *
     * @throws ShareException
     */
    private function issueInvitation(ComicShare $share, Comic $comic, User $owner): IssuedInvitation
    {
        $this->revokeOutstandingTokens($share);

        [$plaintext, $hash] = ShareInvitationToken::generate();
        $token = new ShareInvitationToken($share, $hash, $this->invitationExpiry());
        $this->entityManager->persist($token);

        // Avoid nesting commits when a caller already owns the transaction, the
        // same way account deletion does — a nested commit would escape the
        // test suite's rollback wrapper.
        $connection = $this->entityManager->getConnection();
        $ownsTransaction = !$connection->isTransactionActive();

        try {
            if ($ownsTransaction) {
                $this->entityManager->beginTransaction();
            }

            $this->entityManager->flush();
            $this->sendInvitationEmail($share, $comic, $owner, $plaintext);

            if ($ownsTransaction) {
                $this->entityManager->commit();
            }
        } catch (\Throwable $exception) {
            if ($ownsTransaction && $connection->isTransactionActive()) {
                $this->entityManager->rollback();
            }

            if ($exception instanceof ShareException) {
                throw $exception;
            }

            $this->logger->error('Failed to send a comic share invitation.', [
                'comic_id' => $comic->getId(),
                'exception' => $exception,
            ]);

            throw new ShareException(
                'The invitation email could not be sent, so no invitation was created.',
                502
            );
        }

        return new IssuedInvitation($share, $this->invitationUrl($plaintext));
    }

    private function sendInvitationEmail(ComicShare $share, Comic $comic, User $owner, string $plaintextToken): void
    {
        $ownerName = $owner->getName() ?: $owner->getEmail();

        $body = $this->twig->render('emails/share_comic.html.twig', [
            'comic' => $comic,
            'userName' => $ownerName,
            'shareLink' => $this->invitationUrl($plaintextToken),
            'privacyUrl' => rtrim($this->frontendUrl, '/') . '/privacy',
            'expiresAt' => $share->getExpiresAt(),
        ]);

        $email = (new Email())
            ->from(new Address($this->mailerFromAddress, $this->mailerFromName))
            ->replyTo((string) $owner->getEmail())
            ->to($share->getRecipientEmailNormalized())
            ->subject($ownerName . ' shared a comic with you!')
            ->html($body);

        $this->mailer->send($email);
    }

    public function invitationUrl(string $plaintextToken): string
    {
        return rtrim($this->frontendUrl, '/') . '/share/invitation/' . $plaintextToken;
    }

    /**
     * Retire every link previously issued for this relationship, so a forwarded
     * or cached older email cannot be used after the state has moved on.
     */
    private function revokeOutstandingTokens(ComicShare $share): void
    {
        foreach ($share->getInvitationTokens() as $token) {
            if ($token->getUsedAt() === null) {
                $token->revoke();
            }
        }
    }

    /**
     * @throws ShareException
     */
    private function assertRecipient(ComicShare $share, User $user): void
    {
        $userEmail = ComicShare::normaliseEmail((string) $user->getEmail());
        $isRecipient = $share->getRecipientEmailNormalized() === $userEmail
            || $share->getRecipientUser()?->getId() === $user->getId();

        if (!$isRecipient) {
            throw new ShareException('This invitation was sent to a different account.', 403);
        }
    }

    /**
     * @throws ShareException
     */
    private function assertAnswerable(ComicShare $share): void
    {
        if ($share->isTombstoned()) {
            throw new ShareException('The shared comic is no longer available.', 410);
        }

        if ($share->getStatus() === ComicShare::STATUS_REVOKED) {
            throw new ShareException('The owner has withdrawn this invitation.', 410);
        }

        if ($share->getStatus() === ComicShare::STATUS_ACCEPTED) {
            throw new ShareException('You have already accepted this invitation.', 409);
        }

        if ($share->getStatus() !== ComicShare::STATUS_PENDING) {
            throw new ShareException('This invitation has already been answered.', 409);
        }

        if ($share->isExpired()) {
            throw new ShareException('This invitation has expired.', 410);
        }
    }

    /**
     * @throws ShareException
     */
    private function assertWithinInvitationRate(User $owner): void
    {
        $sent = $this->shareRepository->countInvitationsSentSince(
            $owner,
            new \DateTimeImmutable('-1 hour')
        );

        if ($sent >= self::MAX_INVITATIONS_PER_HOUR) {
            throw new ShareException(
                'You have sent too many invitations recently. Please try again later.',
                429
            );
        }
    }

    private function invitationExpiry(): \DateTimeImmutable
    {
        return (new \DateTimeImmutable())->modify(self::INVITATION_TTL);
    }
}
