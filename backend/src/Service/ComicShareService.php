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
use Symfony\Component\RateLimiter\RateLimiterFactory;
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
    /**
     * How long an unanswered invitation stays open — the link and the pending
     * relationship together, so the two can never disagree about whether an
     * invitation is still live.
     *
     * Two months, because answering one is not always quick: the recipient may
     * have no account here yet, and a week is easy to lose to a holiday or a
     * full inbox. The cost is that a link which escapes — forwarded, scanned,
     * sitting in a proxy log — stays live for longer, which is why the window is
     * not the thing keeping anybody out. A link only ever previews; accepting
     * requires signing in as the intended recipient, and an explicit comic
     * reveals nothing to a link holder at all.
     */
    public const INVITATION_TTL = '+2 months';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ComicShareRepository $shareRepository,
        private readonly ShareInvitationTokenRepository $tokenRepository,
        private readonly MailerInterface $mailer,
        private readonly Environment $twig,
        private readonly LoggerInterface $logger,
        private readonly SecurityAuditLogger $auditLogger,
        private readonly RateLimiterFactory $shareInvitationLimiter,
        private readonly PublicUrl $publicUrl,
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
     * @param bool $senderResponsibilityAccepted the sender's acknowledgement
     *                                           that they are responsible for
     *                                           what they hand out, and for
     *                                           having classified it correctly
     *
     * @throws ShareException
     */
    public function invite(
        Comic $comic,
        User $owner,
        string $recipientEmail,
        bool $senderResponsibilityAccepted
    ): IssuedInvitation {
        // Checked before anything else is decided, so a share cannot come into
        // existence without the acknowledgement that goes on the record with it.
        // The tick box in the UI is a prompt, not the rule.
        if (!$senderResponsibilityAccepted) {
            throw new ShareException(
                'You must acknowledge responsibility for the content you share.',
                400,
                ShareException::CODE_RESPONSIBILITY_REQUIRED
            );
        }

        $email = ComicShare::normaliseEmail($recipientEmail);

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new ShareException('A valid recipient email address is required.', 400);
        }

        if ($email === ComicShare::normaliseEmail((string) $owner->getEmail())) {
            throw new ShareException('You already own this comic.', 400);
        }

        // Keyed on the comic, so this only ever finds a live relationship: a
        // tombstone holds a null comic and is invisible here by construction.
        // That is deliberate. A tombstone belongs to the recipient, as the
        // record of a comic that went away; re-pointing it at a different comic
        // would rewrite their history and delete the explanation they were left
        // with. Re-inviting somebody after a deletion starts a fresh
        // relationship, and the null comic keeps it clear of the unique index.
        $share = $this->shareRepository->findForComicAndRecipient($comic, $email);

        if ($share !== null && $share->getStatus() === ComicShare::STATUS_ACCEPTED) {
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
            // Declined, revoked, or a pending invitation that lapsed. The row is
            // reused rather than duplicated, which is what the unique index on
            // (comic, recipient) is there to guarantee.
            //
            // Reusing the row does not carry the old age declaration forward:
            // this is a fresh offer of the comic as it is now, and the previous
            // relationship ended without the recipient reading anything.
            $share->setOwner($owner)->refreshSnapshots()->resetAdultConfirmation();
        }

        $share->markPending($this->invitationExpiry());
        // A new share, so a new acknowledgement. Resending does not come through
        // here and keeps the timestamp it already has.
        $share->acceptSenderResponsibility();

        $invitation = $this->issueInvitation($share, $comic, $owner);

        // After the send, so nothing is recorded as shared that was rolled back
        // when the email failed.
        $this->auditLogger->audit(SecurityAuditLogger::SHARE_CREATED, [
            'actor_user_id' => $owner->getId(),
            'target_type' => 'share',
            'target_id' => $share->getId(),
            'comic_id' => $comic->getId(),
            'recipient_user_id' => $share->getRecipientUser()?->getId(),
            'explicit_content' => $comic->isExplicitContent(),
        ]);

        // Its own record rather than a field on the one above, because this is
        // the acknowledgement's audit trail and somebody looking for it should
        // not have to know that shares are created with one attached. Ids only:
        // the canonical evidence is ComicShare::senderResponsibilityAcceptedAt,
        // and the title of an explicit comic is the thing the gate withholds.
        $this->auditLogger->audit(SecurityAuditLogger::SHARE_SENDER_RESPONSIBILITY_ACCEPTED, [
            'actor_user_id' => $owner->getId(),
            'target_type' => 'share',
            'target_id' => $share->getId(),
            'comic_id' => $comic->getId(),
            'recipient_user_id' => $share->getRecipientUser()?->getId(),
            'accepted_at' => $share->getSenderResponsibilityAcceptedAt()?->format(DATE_ATOM),
        ]);

        return $invitation;
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
        // Validate before spending the token. Somebody who is not the recipient
        // must not be able to mark a link used on their way to a 403 — the
        // controller happens not to flush after that, but a link that only
        // survives because nothing later in the request writes is not a link
        // the legitimate recipient can rely on. The age gate is checked here for
        // the same reason: being sent to the warning must not cost the recipient
        // the link they need once they have answered it.
        $share = $token->getComicShare();
        $this->assertRecipient($share, $recipient);
        $this->assertAdultConfirmed($share, $recipient);
        $token->markUsed();

        return $this->acceptShare($share, $recipient);
    }

    /**
     * @throws ShareException
     */
    public function decline(ShareInvitationToken $token, User $recipient): ComicShare
    {
        $share = $token->getComicShare();
        $this->assertRecipient($share, $recipient);
        $token->markUsed();

        return $this->declineShare($share, $recipient);
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
        // The warning on the invitation page is a prompt; this is the boundary.
        // Accepting is what puts a comic in somebody's collection, so an
        // unconfirmed explicit share must not get that far however the request
        // was made.
        $this->assertAdultConfirmed($share, $recipient);

        $share->markAccepted($recipient)->refreshSnapshots();
        // Every link that was issued for this invitation is spent now.
        $this->revokeOutstandingTokens($share);
        $this->entityManager->flush();

        $this->auditLogger->audit(SecurityAuditLogger::SHARE_ACCEPTED, [
            'actor_user_id' => $recipient->getId(),
            'target_type' => 'share',
            'target_id' => $share->getId(),
            'comic_id' => $share->getComic()?->getId(),
            'owner_user_id' => $share->getOwner()?->getId(),
            'explicit_content' => $share->isExplicitContent(),
        ]);

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

        $this->auditLogger->audit(SecurityAuditLogger::SHARE_DECLINED, [
            'actor_user_id' => $recipient->getId(),
            'target_type' => 'share',
            'target_id' => $share->getId(),
            'comic_id' => $share->getComic()?->getId(),
            'owner_user_id' => $share->getOwner()?->getId(),
        ]);

        return $share;
    }

    /**
     * Record that this recipient has declared they are 18 or older, for this
     * share.
     *
     * The declaration belongs to the share and not to the account: somebody may
     * be willing to make it about one comic and not about the next, and a
     * per-account flag would answer for them.
     *
     * Idempotent — a second confirmation keeps the first timestamp — so a
     * retried request cannot rewrite when the declaration was actually made.
     *
     * @throws ShareException
     */
    public function confirmAdult(ComicShare $share, User $recipient): ComicShare
    {
        $this->assertRecipient($share, $recipient);

        if ($share->isTombstoned() || $share->getComic() === null) {
            throw new ShareException('The shared comic is no longer available.', 410);
        }

        if ($share->getStatus() === ComicShare::STATUS_REVOKED) {
            throw new ShareException('The owner has withdrawn this invitation.', 410);
        }

        if ($share->getStatus() === ComicShare::STATUS_DECLINED) {
            throw new ShareException('You have already declined this invitation.', 409);
        }

        // Only a pending invitation runs out. An accepted share has no expiry —
        // markAccepted clears it — so this cannot lock out somebody re-gated
        // long after they accepted.
        if ($share->isExpired()) {
            throw new ShareException('This invitation has expired.', 410);
        }

        if (!$share->getComic()->isExplicitContent()) {
            throw new ShareException('This comic is not marked as explicit content.', 409);
        }

        $share->confirmAdult();
        $this->entityManager->flush();

        // Audit, not security, and deliberately not alertable: somebody
        // declaring their age is the feature working. Ids and the server's
        // timestamp only — the canonical evidence is ComicShare::adultConfirmedAt,
        // and nothing about what the comic contains belongs here.
        $this->auditLogger->audit(SecurityAuditLogger::SHARE_ADULT_CONFIRMED, [
            'actor_user_id' => $recipient->getId(),
            'target_type' => 'share',
            'target_id' => $share->getId(),
            'comic_id' => $share->getComic()?->getId(),
            'confirmed_at' => $share->getAdultConfirmedAt()?->format(DATE_ATOM),
        ]);

        return $share;
    }

    /**
     * Re-close the age gate on every live share of a comic that has just been
     * marked explicit.
     *
     * Fails closed by design. Those recipients agreed to read something that was
     * not classified 18+, and an old silence is not a declaration about the
     * comic as it is now — so access stops until each of them says so. The
     * relationship, its status and the recipient's place in their own collection
     * are untouched: this suspends reading, it does not undo the share.
     *
     * Mutates rather than issuing a bulk UPDATE, and asks the repository for
     * only the shares that have something to reset. A DQL UPDATE would be one
     * query, but it writes round the identity map — a share already loaded in
     * this request would go on reporting a confirmation the database no longer
     * holds — and it would commit on its own, splitting the single flush that
     * makes the re-gate and the reclassification land together.
     *
     * @return int the number of shares re-gated
     */
    public function regateSharesForComic(Comic $comic): int
    {
        $regated = 0;

        foreach ($this->shareRepository->findConfirmedSharesForComic($comic) as $share) {
            $share->resetAdultConfirmation();
            ++$regated;
        }

        return $regated;
    }

    /** Withdraw one recipient's access. Takes effect on the next request. */
    public function revoke(ComicShare $share): void
    {
        $share->refreshSnapshots()->markRevoked();
        $this->revokeOutstandingTokens($share);
        $this->entityManager->flush();

        $this->auditLogger->audit(SecurityAuditLogger::SHARE_REVOKED, [
            'actor_user_id' => $share->getOwner()?->getId(),
            'target_type' => 'share',
            'target_id' => $share->getId(),
            'comic_id' => $share->getComic()?->getId(),
            'recipient_user_id' => $share->getRecipientUser()?->getId(),
            'scope' => 'single',
        ]);
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

        // One record for the whole sweep, with a count. Per-share entries would
        // say the same thing many times and bury the fact that this was a single
        // deliberate act. Nothing at all when the sweep found nothing: a record
        // saying "revoked 0" reports an operation that did not happen.
        if ($shares !== []) {
            $this->auditLogger->audit(SecurityAuditLogger::SHARE_REVOKED, [
                'actor_user_id' => $comic->getOwner()?->getId(),
                'target_type' => 'comic',
                'target_id' => $comic->getId(),
                'comic_id' => $comic->getId(),
                'count' => count($shares),
                'scope' => 'all_recipients',
            ]);
        }

        return count($shares);
    }

    /**
     * Hide a shared comic from the recipient's collection without giving it up.
     *
     * Guarded the same way as restoring, so a direct API call cannot set
     * recipientRemovedAt on a pending, declined or revoked share that was never
     * in the collection to begin with.
     *
     * @throws ShareException
     */
    public function removeFromCollection(ComicShare $share): void
    {
        if (!$share->grantsAccess()) {
            throw new ShareException('This comic is not in your collection.', 410);
        }

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

        // Nothing is recorded here. This runs inside a transaction the caller
        // owns and has not committed, so an audit line written now would claim a
        // deletion that a rollback could still undo — and an audit stream that
        // reports operations which did not happen is worse than one that is
        // quiet. The callers log once their commit has returned, and the count
        // this returns is what they put in that record.
        return $accepted;
    }

    /** How many recipients a deletion would affect, for the owner's warning. */
    public function countLiveShares(Comic $comic): int
    {
        return $this->shareRepository->countLiveSharesForComic($comic);
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

            $this->auditLogger->audit(SecurityAuditLogger::SHARES_CLEARED, [
                'actor_user_id' => $recipient->getId(),
                'target_type' => 'share',
                'count' => $removed,
                'selective' => $shareIds !== null,
            ]);
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
        // The last thing checked before anything is created or sent, so only
        // invitations that actually go out count against the allowance.
        $this->reserveInvitationAllowance($owner);
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

        // An email is the least controlled surface there is: it sits in an inbox,
        // gets previewed on a lock screen and is scanned on the way. For an
        // explicit comic the template is given no title and no cover to show, so
        // the identifying details stay behind the age gate rather than being
        // announced by the notification of it.
        $body = $this->twig->render('emails/share_comic.html.twig', [
            'comic' => $comic,
            'explicitContent' => $comic->isExplicitContent(),
            'userName' => $ownerName,
            'shareLink' => $this->invitationUrl($plaintextToken),
            'privacyUrl' => $this->publicUrl->to('/privacy'),
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
        return $this->publicUrl->to('/share/invitation/'.$plaintextToken);
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
            // Somebody signed in as one account acting on an invitation
            // addressed to another. Usually a forwarded link opened by the wrong
            // person in the household; repeatedly, from one account, it is
            // somebody working through share ids to see what opens.
            $this->auditLogger->suspicious(
                SecurityAuditLogger::SHARE_WRONG_RECIPIENT,
                'user:' . $user->getId(),
                [
                    'actor_user_id' => $user->getId(),
                    'target_type' => 'share',
                    'target_id' => $share->getId(),
                    'explicit_content' => $share->isExplicitContent(),
                ],
                5
            );

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
    private function assertAdultConfirmed(ComicShare $share, User $actor): void
    {
        if ($share->requiresAdultConfirmation()) {
            // The UI cannot reach this: it shows the declaration first and only
            // then the accept button. Arriving here means the accept call was
            // made directly, which is the age gate being tested rather than
            // used — so it is a security record and not an audit one, and
            // repetition from one account is worth telling an administrator
            // about.
            $this->auditLogger->suspicious(
                SecurityAuditLogger::ADULT_GATE_BYPASS_ATTEMPT,
                'user:' . $actor->getId(),
                [
                    'actor_user_id' => $actor->getId(),
                    'target_type' => 'share',
                    'target_id' => $share->getId(),
                    'comic_id' => $share->getComic()?->getId(),
                    'reason' => 'accept_without_adult_confirmation',
                ],
                5
            );

            throw new ShareException(
                'You must confirm that you are 18 or older to access this shared comic.',
                403,
                ShareException::CODE_ADULT_CONFIRMATION_REQUIRED
            );
        }
    }

    /**
     * Claim one invitation against the owner's hourly allowance.
     *
     * Reserves rather than checks. Counting sent invitations and then creating
     * another is a read followed by a write, and concurrent requests can all
     * read the same figure and all decide they are under the limit — which is
     * exactly the thing a rate limit exists to stop. The framework's limiter
     * takes a lock around the whole read-decide-record step, so the allowance
     * holds however many requests arrive at once.
     *
     * Called immediately before an invitation is issued, so a request rejected
     * as a duplicate or by permissions does not spend anybody's allowance.
     *
     * @throws ShareException
     */
    private function reserveInvitationAllowance(User $owner): void
    {
        $limit = $this->shareInvitationLimiter->create((string) $owner->getId())->consume();

        if (!$limit->isAccepted()) {
            $retryAfter = max(1, $limit->getRetryAfter()->getTimestamp() - time());

            throw new ShareException(
                sprintf(
                    'You have sent too many invitations recently. Please try again in %d minute(s).',
                    (int) ceil($retryAfter / 60)
                ),
                429
            );
        }
    }

    private function invitationExpiry(): \DateTimeImmutable
    {
        return (new \DateTimeImmutable())->modify(self::INVITATION_TTL);
    }
}
