<?php

namespace App\Service;

use App\Entity\Comic;
use App\Entity\ComicShare;
use App\Entity\ShareInvitationToken;
use App\Entity\User;
use App\Repository\ComicShareRepository;
use App\Message\ShareInvitationNotification;
use App\Repository\ShareInvitationTokenRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Messenger\MessageBusInterface;
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

    /**
     * How many invitations one email will carry a link for.
     *
     * Past this the notice becomes a summary: a headline, a sample of what is
     * in it, and one link to the Sharing page where every invitation is waiting
     * anyway. A folder share can carry two hundred comics, and two hundred
     * buttons is not a message anybody reads — nor one most clients render
     * whole, since Gmail clips a message past about 102KB and would take the
     * ending with it.
     *
     * Deliberately equal to {@see SharingWorkflowService::MAX_BULK_COMICS}, so
     * every share that was possible before folder sharing existed still gets
     * exactly the email it always got.
     */
    public const MAX_LISTED_INVITATIONS = 20;

    /** How many titles a summarised notice names before it stops counting. */
    private const SUMMARY_SAMPLE_SIZE = 10;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ComicShareRepository $shareRepository,
        private readonly ShareInvitationTokenRepository $tokenRepository,
        private readonly MailerInterface $mailer,
        private readonly Environment $twig,
        private readonly LoggerInterface $logger,
        private readonly SecurityAuditLogger $auditLogger,
        private readonly RateLimiterFactory $shareInvitationLimiter,
        private readonly MessageBusInterface $messageBus,
        private readonly PublicUrl $publicUrl,
        #[Autowire('%mailer_from_address%')]
        private readonly string $mailerFromAddress,
        #[Autowire('%mailer_from_name%')]
        private readonly string $mailerFromName,
    ) {
    }

    /**
     * Share several comics with one recipient as a single act.
     *
     * Every comic still gets its own {@see ComicShare} and can be withdrawn on
     * its own after acceptance. When the source is a folder, those grants share
     * one invitation batch: one token, one email and one accept or decline.
     *
     * Two things are deliberately shared across the batch rather than repeated
     * per comic:
     *
     * - **One email.** Twenty comics must not mean twenty messages in somebody's
     *   inbox. A folder notice also has one link for the whole folder.
     * - **One allowance.** One send is one claim on the `share_invitation`
     *   limiter, so what the limiter protects — how much mail one account can
     *   put in somebody's inbox — is exactly what it protected before bulk
     *   sharing existed.
     *
     * The relationships are committed first and announced afterwards. SMTP is
     * not a participant in a database transaction, and the previous arrangement
     * — send inside the transaction, roll back on failure — bought "no
     * invitation nobody was told about" at the price of losing a perfectly good
     * share every time a mail server was briefly busy. The share is the thing
     * that is true; the email is an announcement of it, queued as
     * {@see \App\Message\ShareInvitationNotification} and retryable, with its
     * delivery state on the row so the owner can see it did not arrive and
     * press resend.
     *
     * The caller is responsible for having checked {@see ComicVoter::SHARE} on
     * every comic it passes in.
     *
     * @param list<Comic> $comics
     *
     * @return array<int, array{status: string, message?: string, code?: string, shareId?: int|null, notificationState?: string}>
     *                                                                                                 keyed by comic id
     *
     * @throws ShareException when the whole batch is refused — a bad recipient,
     *                        a missing acknowledgement or an exhausted allowance
     */
    public function inviteMany(
        array $comics,
        User $owner,
        string $recipientEmail,
        bool $senderResponsibilityAccepted,
        ?SharingCodeRecipient $viaSharingCode = null,
        ?int $sourceFolderId = null,
        ?string $sourceFolderName = null
    ): array {
        $this->assertSenderResponsibility($senderResponsibilityAccepted);
        $email = $this->assertInvitableRecipient($owner, $recipientEmail);

        /** @var array<int, array{status: string, message?: string, code?: string, shareId?: int|null, notificationState?: string}> $outcomes */
        $outcomes = [];
        $invitable = [];
        $existing = $this->shareRepository->findForComicsAndRecipient($comics, $email);

        // Read-only pass first. Every comic is judged before any of them is
        // created, so the allowance is claimed once and only for a send that is
        // known to have something to announce.
        foreach ($comics as $comic) {
            $comicId = (int) $comic->getId();

            try {
                $this->assertSharingAvailable($comic, $owner);
                $invitable[$comicId] = [$comic, $this->assertNoLiveInvitation($existing[$comicId] ?? null)];
            } catch (ShareException $exception) {
                $outcomes[$comicId] = $this->describeFailure($exception);
            }
        }

        if ($invitable === []) {
            return $outcomes;
        }

        $this->reserveInvitationAllowance($owner);

        $prepared = [];
        $batchId = $sourceFolderId === null ? null : bin2hex(random_bytes(16));
        $batchSize = count($invitable);
        foreach ($invitable as $comicId => [$comic, $reusable]) {
            $prepared[$comicId] = $this->openInvitation($reusable, $comic, $owner, $email);

            if ($batchId !== null && $sourceFolderName !== null) {
                $prepared[$comicId]->joinInvitationBatch($batchId, $sourceFolderName, $batchSize);
            } else {
                // A declined/revoked row can be reused by a later hand-picked
                // invitation. It must not retain the decision boundary of the
                // old folder offer.
                $prepared[$comicId]->leaveInvitationBatch();
            }

            // The sender reached this person through their receiver code and
            // never saw the address, so the record carries what the sender may
            // be shown in its place — and the owner-facing serializer reads
            // that instead of the address from here on.
            //
            // Cleared in the other direction for the same reason. openInvitation
            // reuses a declined, revoked or lapsed row, so an owner who reaches
            // the same person again by typing their address has plainly been
            // told it — going on hiding it would withhold something they
            // already hold.
            if ($viaSharingCode !== null) {
                $prepared[$comicId]
                    ->hideRecipientBehindSharingCode(
                        $viaSharingCode->userCode,
                        $viaSharingCode->name
                    )
                    // A code is an account, so this relationship knows who it is
                    // for before they answer — and that link, not the stored
                    // code, is what still points at them after a rotation.
                    ->linkRecipientUser($viaSharingCode->user);
            } else {
                $prepared[$comicId]->revealRecipientAddressToOwner();
            }
        }

        $this->commit();

        foreach ($prepared as $comicId => $share) {
            $this->auditInvitation($share, $owner);

            $outcomes[$comicId] = [
                'status' => 'created',
                'shareId' => $share->getId(),
                'notificationState' => $share->getNotificationState(),
            ];
        }

        // After the commit, and carrying ids rather than a rendered message:
        // the worker reloads the relationships and mints the links at the
        // moment it sends them, so no plaintext token is ever written to the
        // queue and a notice retried later carries a link that still works.
        try {
            $this->messageBus->dispatch(new ShareInvitationNotification(
                (int) $owner->getId(),
                array_values(array_map(
                    static fn (ComicShare $share): int => (int) $share->getId(),
                    $prepared
                )),
                $sourceFolderId
            ));
        } catch (\Throwable $exception) {
            // The shares are committed and the owner has them. A broker that is
            // down is a delivery failure, and letting it out of here would tell
            // the owner the share did not happen — then a retry would meet its
            // own duplicates. That is the half-success this whole design exists
            // to prevent, arriving one step later than it used to.
            foreach ($prepared as $comicId => $share) {
                $share->markNotificationFailed();
                $outcomes[$comicId]['notificationState'] = $share->getNotificationState();
            }

            try {
                $this->entityManager->flush();
            } catch (\Throwable $stateFailure) {
                // Best effort. Losing the delivery state must not replace the
                // outcome the owner is entitled to.
                $this->logger->error('A share notification failure could not be recorded.', [
                    'owner_user_id' => $owner->getId(),
                    'exception' => $stateFailure,
                ]);
            }

            $this->logger->error('A share invitation notice could not be queued.', [
                'owner_user_id' => $owner->getId(),
                'exception' => $exception,
            ]);
        }

        /** @var array<int, array{status: string, message?: string, code?: string, shareId?: int|null, notificationState?: string}> $outcomes */
        return $outcomes;
    }

    /**
     * Open one relationship because the recipient redeemed a claim code.
     *
     * The third way a {@see ComicShare} comes into existence, and it lives here
     * with the other two so there is one place that owns what a share is and
     * how it changes. A transport that grew its own copy of these transitions
     * would drift from them — the acknowledgement timestamp being recreated at
     * redemption time is exactly the kind of bug that follows.
     *
     * It differs from an emailed invitation in three ways, all deliberate:
     *
     * - no token and no email. The recipient is right here; there is nothing to
     *   send them a link to
     * - **redeeming an ordinary comic counts as accepting.** Typing a code
     *   somebody gave you is an affirmative act, so a non-explicit comic lands
     *   in the collection rather than waiting to be accepted a second time
     * - the acknowledgement is inherited, not made. The owner acknowledged
     *   responsibility when they created the code
     *
     * Everything else is the ordinary model. The age gate in particular is not
     * negotiable: an explicit comic is left pending, decided *before* the share
     * is accepted so there is no instant in which an unconfirmed recipient
     * holds an accepted share.
     *
     * Does not flush. The caller owns the transaction, because spending a use
     * of the code and creating the shares it paid for have to land together.
     *
     * @param \DateTimeImmutable $acknowledgedAt when the owner accepted
     *                                          responsibility, from the code
     *
     * @return array{status: string, message?: string} the outcome for this comic
     */
    public function claimFromCode(
        Comic $comic,
        User $owner,
        User $recipient,
        \DateTimeImmutable $acknowledgedAt
    ): array {
        $this->assertSharingAvailable($comic, $owner);
        $email = ComicShare::normaliseEmail((string) $recipient->getEmail());
        $share = $this->shareRepository->findForComicAndRecipient($comic, $email);

        // Already theirs, or already offered and still open. Redeeming does not
        // restart either.
        if ($share !== null && ($share->getStatus() === ComicShare::STATUS_ACCEPTED || $share->isPending())) {
            return ['status' => 'already_yours', 'message' => 'You already have this comic.'];
        }

        $share = $this->openOrReuseShare($share, $comic, $owner, $email);

        $share
            ->markPending($this->invitationExpiry())
            ->refreshSnapshots()
            ->linkRecipientUser($recipient)
            // The owner never typed this address and was never told it: they
            // put a code into the world and somebody they may not know picked
            // it up. Linking the account without saying so is what let the
            // redeemer's address surface on the owner's Sharing page later,
            // which turns "hand this to a stranger" into "collect addresses
            // from strangers". They get the recipient's public identity, which
            // is exactly what the recipient publishes.
            ->hideRecipientBehindSharingCode($recipient->getUserCode(), $recipient->getName())
            ->inheritSenderResponsibility($acknowledgedAt);

        $this->auditLogger->audit(SecurityAuditLogger::SHARE_CREATED, [
            'actor_user_id' => $owner->getId(),
            'target_type' => 'share',
            'target_id' => $share->getId(),
            'comic_id' => $comic->getId(),
            'recipient_user_id' => $recipient->getId(),
            'explicit_content' => $comic->isExplicitContent(),
            'via' => 'claim_code',
        ]);

        if ($share->requiresAdultConfirmation()) {
            return [
                'status' => 'awaiting_age_confirmation',
                'message' => 'Confirm your age on the Sharing page to open this one.',
            ];
        }

        $share->markAccepted($recipient)->refreshSnapshots();
        $this->revokeOutstandingTokens($share);

        $this->auditLogger->audit(SecurityAuditLogger::SHARE_ACCEPTED, [
            'actor_user_id' => $recipient->getId(),
            'target_type' => 'share',
            'target_id' => $share->getId(),
            'comic_id' => $comic->getId(),
            'owner_user_id' => $owner->getId(),
            'explicit_content' => $comic->isExplicitContent(),
            'via' => 'claim_code',
        ]);

        return ['status' => 'claimed'];
    }

    /**
     * Send a fresh link for an invitation that is already pending, invalidating
     * the previous one.
     *
     * This is the manual counterpart to the queued notice, and it is deliberately
     * synchronous. Somebody pressing "resend" is standing in front of the screen
     * asking whether the email went this time, so a failure is reported to them
     * rather than retried quietly an hour later — and the link comes back in the
     * response, which is the way out when the mail is not arriving at all.
     *
     * The relationship survives a failed send either way. It already existed.
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

        $this->assertSharingAvailable($comic, $owner);

        if ($share->getStatus() === ComicShare::STATUS_ACCEPTED) {
            throw new ShareException('That invitation has already been accepted.', 409);
        }

        if ($share->getInvitationBatchId() !== null && $share->getStatus() === ComicShare::STATUS_PENDING) {
            $members = array_values(array_filter(
                $this->invitationMembers($share),
                static fn (ComicShare $member): bool => $member->getStatus() === ComicShare::STATUS_PENDING
                    && !$member->isTombstoned()
                    && $member->getComic() !== null
            ));
            foreach ($members as $member) {
                $memberComic = $member->getComic();
                $memberOwner = $member->getOwner();
                if ($memberComic === null || $memberOwner === null) {
                    throw new ShareException('This folder invitation is no longer available to resend.', 410);
                }
                $this->assertSharingAvailable($memberComic, $memberOwner);
                $member->markPending($this->invitationExpiry())->refreshSnapshots()->awaitNotification();
            }

            $this->reserveInvitationAllowance($owner);
            try {
                $url = $this->notify($members, $owner, $share->getInvitationBatchName());
            } catch (\Throwable $exception) {
                foreach ($members as $member) {
                    $member->markNotificationFailed();
                }
                $this->entityManager->flush();

                $this->logger->error('Failed to resend a folder share invitation.', [
                    'invitation_batch_id' => $share->getInvitationBatchId(),
                    'share_ids' => array_map(
                        static fn (ComicShare $member): ?int => $member->getId(),
                        $members
                    ),
                    'exception' => $exception,
                ]);

                throw new ShareException(
                    'The folder invitation email could not be sent. The shares are unaffected — try resending. '
                    .'If the recipient has an account, the invitation is waiting on their Sharing page.',
                    502
                );
            }

            return new IssuedInvitation($share, (string) $url);
        }

        // Claimed before the row is touched, so a resend that is turned away
        // leaves the invitation exactly as it was.
        $this->reserveInvitationAllowance($owner);

        $share->markPending($this->invitationExpiry())->refreshSnapshots();
        $this->revokeOutstandingTokens($share);

        [$plaintext, $hash] = ShareInvitationToken::generate();
        $this->entityManager->persist(new ShareInvitationToken($share, $hash, $this->invitationExpiry()));
        $share->awaitNotification();
        $this->entityManager->flush();

        try {
            $this->sendInvitationEmail($share, $comic, $owner, $plaintext);
        } catch (\Throwable $exception) {
            $share->markNotificationFailed();
            $this->entityManager->flush();

            $this->logger->error('Failed to resend a comic share invitation.', [
                'share_id' => $share->getId(),
                'comic_id' => $comic->getId(),
                'exception' => $exception,
            ]);

            throw new ShareException(
                'The invitation email could not be sent. The share is unaffected — try resending. '
                .'If the recipient has an account, the invitation is waiting on their Sharing page.',
                502
            );
        }

        $share->markNotified();
        $this->entityManager->flush();

        return new IssuedInvitation($share, $this->invitationUrl($plaintext));
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

        if ($share->getComic()->isSharingRestricted() || $share->getComic()->isQuarantined()) {
            throw new ShareException('This shared comic is temporarily unavailable.', 410);
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
        foreach ($this->invitationDecisionShares($share) as $member) {
            $this->assertRecipient($member, $recipient);
            $this->assertAdultConfirmed($member, $recipient);
        }
        $token->markUsed();

        return $this->acceptShare($share, $recipient);
    }

    /**
     * @throws ShareException
     */
    public function decline(ShareInvitationToken $token, User $recipient): ComicShare
    {
        $share = $token->getComicShare();
        foreach ($this->invitationDecisionShares($share) as $member) {
            $this->assertRecipient($member, $recipient);
        }
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
        $members = $this->invitationDecisionShares($share);

        // Validate the complete folder before changing any row. A single
        // accept is atomic: it never leaves half a folder in the collection.
        foreach ($members as $member) {
            $this->assertRecipient($member, $recipient);
            $comic = $member->getComic();
            if ($comic === null || $comic->isSharingRestricted() || $comic->isQuarantined()) {
                throw new ShareException('One or more comics in this invitation are temporarily unavailable.', 410);
            }
            $this->assertAnswerable($member);
            $this->assertAdultConfirmed($member, $recipient);
        }

        foreach ($members as $member) {
            $member->markAccepted($recipient)->refreshSnapshots();
            // Every link that was issued for this invitation is spent now.
            $this->revokeOutstandingTokens($member);
        }
        $this->entityManager->flush();

        foreach ($members as $member) {
            $this->auditLogger->audit(SecurityAuditLogger::SHARE_ACCEPTED, [
                'actor_user_id' => $recipient->getId(),
                'target_type' => 'share',
                'target_id' => $member->getId(),
                'comic_id' => $member->getComic()?->getId(),
                'owner_user_id' => $member->getOwner()?->getId(),
                'explicit_content' => $member->isExplicitContent(),
                'invitation_batch_id' => $member->getInvitationBatchId(),
            ]);
        }

        return $share;
    }

    /**
     * @throws ShareException
     */
    public function declineShare(ComicShare $share, User $recipient): ComicShare
    {
        $members = $this->invitationDecisionShares($share);
        foreach ($members as $member) {
            $this->assertRecipient($member, $recipient);
            $this->assertAnswerable($member);
        }

        foreach ($members as $member) {
            $member->markDeclined($recipient);
            $this->revokeOutstandingTokens($member);
        }
        $this->entityManager->flush();

        foreach ($members as $member) {
            $this->auditLogger->audit(SecurityAuditLogger::SHARE_DECLINED, [
                'actor_user_id' => $recipient->getId(),
                'target_type' => 'share',
                'target_id' => $member->getId(),
                'comic_id' => $member->getComic()?->getId(),
                'owner_user_id' => $member->getOwner()?->getId(),
                'invitation_batch_id' => $member->getInvitationBatchId(),
            ]);
        }

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
        $members = $share->getStatus() === ComicShare::STATUS_PENDING
            ? $this->invitationDecisionShares($share)
            : [$share];
        $explicit = [];

        foreach ($members as $member) {
            $this->assertRecipient($member, $recipient);

            if ($member->isTombstoned() || $member->getComic() === null) {
                throw new ShareException('The shared comic is no longer available.', 410);
            }
            if ($member->getStatus() === ComicShare::STATUS_REVOKED) {
                throw new ShareException('The owner has withdrawn this invitation.', 410);
            }
            if ($member->getStatus() === ComicShare::STATUS_DECLINED) {
                throw new ShareException('You have already declined this invitation.', 409);
            }
            if ($member->isExpired()) {
                throw new ShareException('This invitation has expired.', 410);
            }
            if ($member->getComic()->isExplicitContent()) {
                $explicit[] = $member;
            }
        }

        if ($explicit === []) {
            throw new ShareException('This invitation is not marked as explicit content.', 409);
        }

        foreach ($explicit as $member) {
            $member->confirmAdult();
        }
        $this->entityManager->flush();

        // Audit, not security, and deliberately not alertable: somebody
        // declaring their age is the feature working. Ids and the server's
        // timestamp only — the canonical evidence is ComicShare::adultConfirmedAt,
        // and nothing about what the comic contains belongs here.
        foreach ($explicit as $member) {
            $this->auditLogger->audit(SecurityAuditLogger::SHARE_ADULT_CONFIRMED, [
                'actor_user_id' => $recipient->getId(),
                'target_type' => 'share',
                'target_id' => $member->getId(),
                'comic_id' => $member->getComic()?->getId(),
                'confirmed_at' => $member->getAdultConfirmedAt()?->format(DATE_ATOM),
                'invitation_batch_id' => $member->getInvitationBatchId(),
            ]);
        }

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
     * Delete the record of a finished share from the owner's side.
     *
     * The owner's counterpart to {@see clearDeadShares()}: revoking, being
     * declined or letting an invitation lapse all leave a row in their sharing
     * list, and this is how they clear it before the retention sweep would.
     *
     * A share that still grants or promises access is refused rather than
     * revoked implicitly. Deleting must never be a quieter way to cut somebody
     * off — revoking is the act that takes access away, leaves the timestamps
     * behind, and is audited as itself.
     *
     * @throws ShareException
     */
    public function deleteForOwner(ComicShare $share): void
    {
        if (!$share->isFinished()) {
            throw new ShareException('Revoke this share before deleting its record.', 409);
        }

        // Read before the remove: a flushed deletion has no id left to audit.
        $context = [
            'actor_user_id' => $share->getOwner()?->getId(),
            'target_type' => 'share',
            'target_id' => $share->getId(),
            'comic_id' => $share->getComic()?->getId(),
            'count' => 1,
            'scope' => 'owner',
        ];

        $this->entityManager->remove($share);
        $this->entityManager->flush();

        $this->auditLogger->audit(SecurityAuditLogger::SHARES_CLEARED, $context);
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
     * The acknowledgement that goes on the record with the share.
     *
     * Checked before anything else is decided, so a share cannot come into
     * existence without it. The tick box in the UI is a prompt, not the rule.
     *
     * @throws ShareException
     */
    private function assertSenderResponsibility(bool $accepted): void
    {
        if (!$accepted) {
            throw new ShareException(
                'You must acknowledge responsibility for the content you share.',
                400,
                ShareException::CODE_RESPONSIBILITY_REQUIRED
            );
        }
    }

    /**
     * @return string the normalised address every later step uses
     *
     * @throws ShareException
     */
    private function assertInvitableRecipient(User $owner, string $recipientEmail): string
    {
        $email = ComicShare::normaliseEmail($recipientEmail);

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new ShareException('A valid recipient email address is required.', 400);
        }

        if ($email === ComicShare::normaliseEmail((string) $owner->getEmail())) {
            throw new ShareException('You cannot share a comic with yourself.', 400);
        }

        return $email;
    }

    /**
     * Refuse a comic this recipient can already reach, and hand back the dead
     * relationship to reuse when there is one.
     *
     * Reads only. Bulk sharing judges every comic in a batch before it creates
     * any of them, so nothing here may write.
     *
     * @return ComicShare|null a declined, revoked or lapsed row to reopen
     *
     * @throws ShareException
     */
    private function assertNoLiveInvitation(?ComicShare $share): ?ComicShare
    {
        // Keyed on the comic, so this only ever finds a live relationship: a
        // tombstone holds a null comic and is invisible here by construction.
        // That is deliberate. A tombstone belongs to the recipient, as the
        // record of a comic that went away; re-pointing it at a different comic
        // would rewrite their history and delete the explanation they were left
        // with. Re-inviting somebody after a deletion starts a fresh
        // relationship, and the null comic keeps it clear of the unique index.
        if ($share !== null && $share->getStatus() === ComicShare::STATUS_ACCEPTED) {
            throw new ShareException('This comic is already shared with that person.', 409);
        }

        if ($share !== null && $share->isPending()) {
            throw new ShareException(
                'An invitation is already pending for that person. Resend it instead.',
                409
            );
        }

        return $share;
    }

    /**
     * The row a fresh offer of this comic to this address will use.
     *
     * Reuse is not a choice: the unique index on (comic, recipient) means a
     * relationship that ended — declined, revoked, or a pending invitation that
     * lapsed — is reopened rather than duplicated. Reopening does not carry the
     * old age declaration forward, because this is an offer of the comic as it
     * is now and the previous relationship ended without the recipient reading
     * anything.
     *
     * Both ways in go through here — an invitation the owner addressed, and a
     * claim somebody made against a code — so the two cannot drift on what
     * reopening means.
     *
     * @param ComicShare|null $share the row already found for this pair, if any
     */
    private function openOrReuseShare(?ComicShare $share, Comic $comic, User $owner, string $email): ComicShare
    {
        if ($share === null) {
            $share = new ComicShare($comic, $owner, $email);
            $this->entityManager->persist($share);

            return $share;
        }

        return $share->setOwner($owner)->refreshSnapshots()->resetAdultConfirmation();
    }

    /**
     * Open the relationship, without flushing, minting or sending.
     *
     * Deliberately does not create an invitation token. A token is a capability
     * with an expiry, and minting it here would mean putting it on a queue and
     * hoping the notice goes out before it lapsed; the worker mints one at the
     * moment it is about to write it into an email instead.
     *
     * @param ComicShare|null $share the row {@see assertNoLiveInvitation} found
     */
    private function openInvitation(?ComicShare $share, Comic $comic, User $owner, string $email): ComicShare
    {
        $share = $this->openOrReuseShare($share, $comic, $owner, $email);

        $share->markPending($this->invitationExpiry());
        // A new share, so a new acknowledgement. Resending does not come through
        // here and keeps the timestamp it already has.
        $share->acceptSenderResponsibility();
        $share->awaitNotification();

        $this->revokeOutstandingTokens($share);

        return $share;
    }

    /**
     * Make the prepared relationships real.
     *
     * Nothing is announced from here. The shares are the authoritative record
     * and the email is a notice about them, so the notice is queued after the
     * commit and cannot take a valid share down with it when a mail server is
     * having a bad afternoon.
     *
     * Avoids nesting commits when a caller already owns the transaction, the
     * same way account deletion does — a nested commit would escape the test
     * suite's rollback wrapper.
     */
    private function commit(): void
    {
        if ($this->entityManager->getConnection()->isTransactionActive()) {
            $this->entityManager->flush();

            return;
        }

        $this->entityManager->beginTransaction();

        try {
            $this->entityManager->flush();
            $this->entityManager->commit();
        } catch (\Throwable $exception) {
            $this->entityManager->rollback();

            throw $exception;
        }
    }

    /**
     * Announce shares that already exist, as one email.
     *
     * Called by the worker rather than by the request that created them, which
     * is what makes minting the links here the right moment: a notice retried
     * after a transport outage carries a link that works, and no plaintext
     * token was ever written to the queue on the way.
     *
     * Marks what it managed to send. A throw leaves the state alone for the
     * handler to record as failed, so "we tried and it did not work" and "we
     * have not tried yet" stay distinguishable on the owner's page.
     *
     * @param list<ComicShare> $shares     all addressed to the same recipient
     * @param string|null      $folderName the folder the sender pointed at, when
     *                                     they shared one, so the notice can say
     *                                     where the comics came from
     */
    public function notify(array $shares, User $owner, ?string $folderName = null): ?string
    {
        if ($shares === []) {
            return null;
        }

        $prepared = [];

        $isFolderBatch = $shares[0]->getInvitationBatchId() !== null;
        $batchPlaintext = null;
        if ($isFolderBatch) {
            [$batchPlaintext, $batchHash] = ShareInvitationToken::generate();
        }

        foreach ($shares as $index => $share) {
            $comic = $share->getComic();
            if ($comic === null) {
                continue;
            }

            $this->revokeOutstandingTokens($share);

            if ($isFolderBatch) {
                // The token anchors the batch to one durable share row, but the
                // decision it resolves covers every row with the same random
                // batch id. Persist it once rather than minting N capabilities
                // that all perform the same action.
                if ($index === 0) {
                    $this->entityManager->persist(new ShareInvitationToken(
                        $share,
                        (string) $batchHash,
                        $this->invitationExpiry()
                    ));
                }
                $plaintext = (string) $batchPlaintext;
            } else {
                [$plaintext, $hash] = ShareInvitationToken::generate();
                $this->entityManager->persist(new ShareInvitationToken($share, $hash, $this->invitationExpiry()));
            }

            $prepared[] = new PreparedInvitation($share, $comic, $plaintext);
        }

        if ($prepared === []) {
            return null;
        }

        // Flushed before the send so the hashes the email's links resolve
        // against are committed by the time anybody can click one.
        $this->entityManager->flush();

        $this->sendGroupedInvitationEmail($prepared, $owner, $folderName);

        foreach ($prepared as $invitation) {
            $invitation->share->markNotified();
        }

        $this->entityManager->flush();

        return $this->invitationUrl($prepared[0]->plaintextToken);
    }

    /** The two records every new sharing relationship leaves behind. */
    private function auditInvitation(ComicShare $share, User $owner): void
    {
        $comic = $share->getComic() ?? throw new \LogicException('A new share must reference a comic.');

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
    }

    /**
     * One comic's failure, in the shape a bulk result reports it.
     *
     * @return array{status: string, message: string, code?: string}
     */
    private function describeFailure(ShareException $exception): array
    {
        $outcome = [
            'status' => match ($exception->getStatusCode()) {
                409 => 'skipped',
                429 => 'rate_limited',
                default => 'failed',
            },
            'message' => $exception->getMessage(),
        ];

        if ($exception->getErrorCode() !== null) {
            $outcome['code'] = $exception->getErrorCode();
        }

        return $outcome;
    }

    private function sendInvitationEmail(ComicShare $share, Comic $comic, User $owner, string $plaintextToken): void
    {
        $ownerName = $owner->getName() ?: '@'.$owner->getUsername();

        // An email is the least controlled surface there is: it sits in an inbox,
        // gets previewed on a lock screen and is scanned on the way. For an
        // explicit comic the template is given no title and no cover to show, so
        // the identifying details stay behind the age gate rather than being
        // announced by the notification of it.
        $body = $this->twig->render('emails/share_comic.html.twig', [
            'comic' => $comic,
            'explicitContent' => $comic->isExplicitContent(),
            'userName' => $ownerName,
            'siteName' => $this->mailerFromName,
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

    /**
     * One message for a whole bulk share.
     *
     * A hand-picked bulk share keeps one link per comic. A folder share carries
     * one link for the whole snapshot, because it is accepted or declined as
     * one invitation even though its resulting grants remain per comic.
     *
     * For a hand-picked bulk share past {@see MAX_LISTED_INVITATIONS}, the links
     * come out and a summary goes in; those independent invitations wait on the
     * Sharing page. A folder batch is different: its summary keeps the single
     * batch link, regardless of how many comics the folder contains.
     *
     * @param list<PreparedInvitation> $prepared
     */
    private function sendGroupedInvitationEmail(array $prepared, User $owner, ?string $folderName = null): void
    {
        $isFolderBatch = $prepared[0]->share->getInvitationBatchId() !== null;
        if ($isFolderBatch && $folderName === null) {
            $folderName = $prepared[0]->share->getInvitationBatchName();
        }

        // One comic is not a group. Falling back keeps the ordinary case on the
        // template that has always described it.
        if (count($prepared) === 1 && !$isFolderBatch) {
            $only = $prepared[0];
            $this->sendInvitationEmail($only->share, $only->comic, $owner, $only->plaintextToken);

            return;
        }

        $ownerName = $owner->getName() ?: '@'.$owner->getUsername();
        $recipient = $prepared[0]->share->getRecipientEmailNormalized();
        $expiresAt = $prepared[0]->share->getExpiresAt();
        $listLinks = !$isFolderBatch && count($prepared) <= self::MAX_LISTED_INVITATIONS;

        $invitations = array_map(
            function (PreparedInvitation $invitation) use ($listLinks): array {
                $explicit = $invitation->comic->isExplicitContent();

                return [
                    // Withheld rather than rendered and hidden: an email is
                    // previewed on lock screens and read by scanners, so an
                    // explicit comic must not be named in one at all.
                    'title' => $explicit ? null : $invitation->comic->getTitle(),
                    'author' => $explicit ? null : $invitation->comic->getAuthor(),
                    'explicitContent' => $explicit,
                    // Not minted into the summary at all. A link that is not
                    // rendered is a capability that never entered the message,
                    // which is the same reason the queue carries ids only.
                    'shareLink' => $listLinks ? $this->invitationUrl($invitation->plaintextToken) : null,
                ];
            },
            $prepared
        );

        $body = $this->twig->render('emails/share_comics.html.twig', [
            'invitations' => $invitations,
            'listLinks' => $listLinks,
            'isFolderBatch' => $isFolderBatch,
            'batchLink' => $isFolderBatch
                ? $this->invitationUrl($prepared[0]->plaintextToken)
                : null,
            // The same age gate the per-comic blocks apply, so a summary cannot
            // become the one place an explicit comic gets named.
            'sampleTitles' => $listLinks ? [] : $this->summaryTitles($invitations),
            'sharingUrl' => $this->publicUrl->to('/sharing'),
            'folderName' => $folderName,
            'comicCount' => count($invitations),
            'explicitCount' => count(array_filter($invitations, static fn (array $i): bool => $i['explicitContent'])),
            'userName' => $ownerName,
            'siteName' => $this->mailerFromName,
            'privacyUrl' => $this->publicUrl->to('/privacy'),
            'expiresAt' => $expiresAt,
        ]);

        $email = (new Email())
            ->from(new Address($this->mailerFromAddress, $this->mailerFromName))
            ->replyTo((string) $owner->getEmail())
            ->to($recipient)
            ->subject($folderName === null
                ? sprintf(
                    '%s shared %d %s with you!',
                    $ownerName,
                    count($invitations),
                    count($invitations) === 1 ? 'comic' : 'comics'
                )
                : sprintf(
                    '%s shared %d %s from "%s" with you!',
                    $ownerName,
                    count($invitations),
                    count($invitations) === 1 ? 'comic' : 'comics',
                    $folderName
                ))
            ->html($body);

        $this->mailer->send($email);
    }

    /**
     * A few titles, so a summary says what it is about rather than only how
     * many. Explicit comics are skipped rather than truncated into: they are
     * counted separately in the same email and naming one here would undo the
     * gate every other part of this file keeps shut.
     *
     * @param list<array{title: string|null, explicitContent: bool}> $invitations
     *
     * @return list<string>
     */
    private function summaryTitles(array $invitations): array
    {
        $titles = [];
        foreach ($invitations as $invitation) {
            if ($invitation['explicitContent'] || $invitation['title'] === null) {
                continue;
            }

            $titles[] = $invitation['title'];
            if (count($titles) === self::SUMMARY_SAMPLE_SIZE) {
                break;
            }
        }

        return $titles;
    }

    /**
     * All durable grants represented by the invitation that contains this row.
     * Public so response presenters can describe the one decision accurately.
     *
     * @return list<ComicShare>
     */
    public function invitationMembers(ComicShare $share): array
    {
        return $this->shareRepository->findInvitationBatch($share);
    }

    /**
     * Rows the recipient's next answer applies to.
     *
     * A grant the owner already withdrew is no longer being offered and does
     * not make the rest of a folder impossible to accept. Expired rows remain
     * here so assertAnswerable can reject the whole still-pending batch.
     *
     * @return list<ComicShare>
     */
    private function invitationDecisionShares(ComicShare $share): array
    {
        if ($share->getInvitationBatchId() === null || $share->getStatus() !== ComicShare::STATUS_PENDING) {
            return [$share];
        }

        $pending = array_values(array_filter(
            $this->invitationMembers($share),
            static fn (ComicShare $member): bool => $member->getStatus() === ComicShare::STATUS_PENDING
                && !$member->isTombstoned()
                && $member->getComic() !== null
        ));

        return $pending === [] ? [$share] : $pending;
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
     * The same question {@see assertSharingAvailable()} answers, as a predicate.
     *
     * Content-code redemption has to judge a whole package before creating any
     * of it — a group is handed over whole or not at all — so it needs to ask
     * rather than to try and catch. Both forms read the one rule.
     */
    public function isShareableBy(Comic $comic, User $owner): bool
    {
        try {
            $this->assertSharingAvailable($comic, $owner);
        } catch (ShareException) {
            return false;
        }

        return true;
    }

    private function assertSharingAvailable(Comic $comic, User $owner): void
    {
        if ($owner->isSharingRestricted()) {
            throw new ShareException('Sharing for this account has been restricted by the service administrator.', 403);
        }

        if ($comic->isSharingRestricted() || $comic->isQuarantined()) {
            throw new ShareException('Sharing for this item has been temporarily restricted by the service administrator.', 403);
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
            throw ShareException::rateLimited('You have sent too many invitations recently.', $limit);
        }
    }

    private function invitationExpiry(): \DateTimeImmutable
    {
        return (new \DateTimeImmutable())->modify(self::INVITATION_TTL);
    }
}
