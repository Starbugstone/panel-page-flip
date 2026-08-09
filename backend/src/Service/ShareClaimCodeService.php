<?php

namespace App\Service;

use App\Entity\Comic;
use App\Entity\ComicShare;
use App\Entity\ShareClaimCode;
use App\Entity\User;
use App\Repository\ComicShareRepository;
use App\Repository\ShareClaimCodeRepository;
use App\Repository\UserRepository;
use App\Security\Voter\ComicVoter;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * Codes an owner hands out so somebody can come and get the comics behind them.
 *
 * The other half of the sharing-code story. A receiver code says "this is me";
 * this says "these are mine, take them" — for the case where the owner does not
 * know, and should not have to ask for, the other person's address.
 *
 * The code is a capability, so the guard rails are on the code and not on the
 * access it produces:
 *
 * - it is stored as a hash, so a database leak yields nothing redeemable
 * - it dies in a day, because a code pasted into a group chat is out of its
 *   owner's hands the moment it is sent
 * - it is spent as it is used, between one and ten times, so the owner decides
 *   up front how far it may travel, and
 * - it grants nothing on its own. Redeeming requires being signed in, and
 *   produces the same ordinary {@see ComicShare} an emailed invitation would
 *
 * That last point is the important one. Nothing downstream of redemption knows
 * or cares that a code was involved: the 18+ gate, revocation, tombstones and
 * the recipient's own housekeeping all behave exactly as they always have.
 */
final class ShareClaimCodeService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ShareClaimCodeRepository $claimCodeRepository,
        private readonly ComicShareRepository $shareRepository,
        private readonly UserRepository $userRepository,
        private readonly ComicShareService $shareService,
        private readonly AuthorizationCheckerInterface $authorizationChecker,
        private readonly SecurityAuditLogger $auditLogger,
        private readonly SharingCodeService $sharingCodes,
        private readonly RateLimiterFactory $shareClaimCodeLimiter,
    ) {
    }

    /**
     * Mint a code for comics this owner may share.
     *
     * @param list<int> $comicIds
     *
     * @return array{code: ShareClaimCode, plaintext: string}
     *
     * @throws ShareException
     */
    public function issue(
        array $comicIds,
        User $owner,
        int $maxUses,
        bool $senderResponsibilityAccepted
    ): array {
        if (!$senderResponsibilityAccepted) {
            throw new ShareException(
                'You must acknowledge responsibility for the content you share.',
                400,
                ShareException::CODE_RESPONSIBILITY_REQUIRED
            );
        }

        if (!ShareClaimCode::isUsableCount($maxUses)) {
            throw new ShareException(
                sprintf(
                    'A code can be used between %d and %d times.',
                    ShareClaimCode::MIN_USES,
                    ShareClaimCode::MAX_USES
                ),
                400
            );
        }

        $ids = array_unique($comicIds);

        // Before the lookups, not after, so an oversized request does not do
        // hundreds of queries on its way to being refused.
        if (count($ids) > SharingWorkflowService::MAX_BULK_COMICS) {
            throw new ShareException(
                sprintf('A code can carry at most %d comics.', SharingWorkflowService::MAX_BULK_COMICS),
                400
            );
        }

        $comics = [];
        foreach ($ids as $comicId) {
            $comic = $this->entityManager->getRepository(Comic::class)->find($comicId);

            // Same silence as the bulk invitation endpoint: a comic that is
            // missing and a comic belonging to somebody else are one answer, so
            // this cannot be used to find out which ids exist.
            if (!$comic instanceof Comic || !$this->authorizationChecker->isGranted(ComicVoter::SHARE, $comic)) {
                throw new ShareException('One or more of those comics is not available to share.', 403);
            }

            $comics[] = $comic;
        }

        if ($comics === []) {
            throw new ShareException('Select at least one comic to share.', 400);
        }

        // Claimed before the code exists, so a refused request leaves nothing
        // behind. Creating a code sends no mail, which is why this is its own
        // allowance rather than the invitation limiter's.
        $this->reserveIssueAllowance($owner);

        $plaintext = $this->mintUniqueCode();
        $code = new ShareClaimCode(
            $owner,
            SharingCodeFormat::hash($plaintext),
            $comics,
            $maxUses,
            ShareClaimCode::expiry()
        );

        $this->entityManager->persist($code);

        try {
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException) {
            // The index is the authority, and it has just refused a code that
            // was free when it was checked. At sixty bits that is not worth a
            // recovery path — and there is no manager left to retry through,
            // because Doctrine closes it when a flush raises this.
            throw new ShareException('A sharing code could not be created. Please try again.', 500);
        }

        $this->auditLogger->audit(SecurityAuditLogger::SHARE_CLAIM_CODE_CREATED, [
            'actor_user_id' => $owner->getId(),
            'target_type' => 'share_claim_code',
            'target_id' => $code->getId(),
            // Ids and counts only. The plaintext is never written anywhere, and
            // the title of an explicit comic is the thing the gate withholds.
            'comic_ids' => array_map(static fn (Comic $comic): ?int => $comic->getId(), $comics),
            'max_uses' => $maxUses,
            'expires_at' => $code->getExpiresAt()->format(DATE_ATOM),
        ]);

        return ['code' => $code, 'plaintext' => $plaintext];
    }

    /**
     * Turn a code somebody was given into invitations addressed to them.
     *
     * A use is spent whether or not every comic on the code was still available
     * to this person: they consumed the offer, and a code that could be probed
     * for free would tell somebody which comics they already have.
     *
     * @return array{results: list<array<string, mixed>>, claimed: int, ownerName: string}
     *
     * @throws ShareException
     */
    public function redeem(string $plaintext, User $redeemer): array
    {
        $code = $this->claimCodeRepository->findByPlaintext($plaintext);

        if ($code === null) {
            $this->rejectRedemption($redeemer, null);
        }

        // Everything from here is one unit of work, and the row is locked
        // before its remaining uses are read. "Check the count, then decrement
        // it" is a read followed by a write: two redemptions arriving together
        // both see the last use, both create shares, and both write zero — so a
        // one-use code would let two people in, which is the single guarantee
        // the count exists to make.
        $connection = $this->entityManager->getConnection();
        $ownsTransaction = !$connection->isTransactionActive();

        try {
            if ($ownsTransaction) {
                $this->entityManager->beginTransaction();
            }

            $result = $this->redeemLocked($code, $redeemer);

            if ($ownsTransaction) {
                $this->entityManager->commit();
            }

            return $result;
        } catch (\Throwable $exception) {
            if ($ownsTransaction && $connection->isTransactionActive()) {
                $this->entityManager->rollback();
            }

            throw $exception;
        }
    }

    /**
     * The redemption itself, with the caller holding the transaction.
     *
     * @return array{results: list<array<string, mixed>>, claimed: int, ownerName: string}
     *
     * @throws ShareException
     */
    private function redeemLocked(ShareClaimCode $code, User $redeemer): array
    {
        $this->entityManager->lock($code, LockMode::PESSIMISTIC_WRITE);
        // Re-read behind the lock, so the count this decision is made on is the
        // committed one rather than whatever was loaded before the wait.
        $this->entityManager->refresh($code);

        // One answer for a code that never existed, one that has been revoked,
        // one that ran out and one that expired. Telling them apart would say
        // whether a guess had ever been a real code.
        if (!$code->isRedeemable()) {
            $this->rejectRedemption($redeemer, $code->getId());
        }

        $owner = $code->getOwner();

        if ($owner === null || $owner->getId() === $redeemer->getId()) {
            // Redeeming your own code is not an attack, and saying so plainly
            // reveals nothing the holder does not already know.
            throw new ShareException('This is your own sharing code.', 409);
        }

        $results = [];
        $claimed = 0;

        foreach ($code->getComics() as $comic) {
            $comicId = (int) $comic->getId();

            $existing = $this->shareRepository->findForComicAndRecipient(
                $comic,
                ComicShare::normaliseEmail((string) $redeemer->getEmail())
            );

            if ($existing !== null && ($existing->getStatus() === ComicShare::STATUS_ACCEPTED || $existing->isPending())) {
                $results[] = [
                    'comicId' => $comicId,
                    'status' => 'already_yours',
                    'message' => 'You already have this comic.',
                ];
                continue;
            }

            $share = $existing ?? new ComicShare($comic, $owner, (string) $redeemer->getEmail());
            if ($existing === null) {
                $this->entityManager->persist($share);
            } else {
                $share->setOwner($owner)->refreshSnapshots()->resetAdultConfirmation();
            }

            // The *code* expires in a day; the relationship it produces does
            // not inherit that. An explicit comic left pending behind the age
            // gate gets the same two months any other invitation would, because
            // the recipient answering it is a separate act from the redemption.
            $share->markPending(new \DateTimeImmutable(ComicShareService::INVITATION_TTL))->refreshSnapshots();
            // The owner's acknowledgement travels with the code rather than
            // being asked for again: they made it when they created the offer,
            // and the person redeeming is not the sender.
            $share->acceptSenderResponsibility();

            // Redeeming is the recipient's own deliberate act, so it stands in
            // for accepting an invitation — with one exception this cannot wave
            // through. An explicit comic is left pending so it is answered
            // behind the age gate on the Sharing page, exactly as an emailed
            // invitation would be. Decided before the share is accepted rather
            // than undone afterwards, so there is no moment where an
            // unconfirmed recipient holds an accepted share.
            if ($share->requiresAdultConfirmation()) {
                $results[] = [
                    'comicId' => $comicId,
                    'status' => 'awaiting_age_confirmation',
                    'message' => 'Confirm your age on the Sharing page to open this one.',
                ];
            } else {
                $share->markAccepted($redeemer);
                $results[] = ['comicId' => $comicId, 'status' => 'claimed'];
            }

            ++$claimed;
        }

        $code->spendUse();
        $this->entityManager->flush();

        $this->auditLogger->audit(SecurityAuditLogger::SHARE_CLAIM_CODE_REDEEMED, [
            'actor_user_id' => $redeemer->getId(),
            'target_type' => 'share_claim_code',
            'target_id' => $code->getId(),
            'owner_user_id' => $owner->getId(),
            'claimed' => $claimed,
            'uses_remaining' => $code->getUsesRemaining(),
        ]);

        return [
            'results' => $results,
            'claimed' => $claimed,
            'ownerName' => $owner->getName() ?: 'A Panel Page Flip reader',
        ];
    }

    /**
     * A code no other code is using, of either kind.
     *
     * The unique index on `code_hash` already makes two claim codes impossible;
     * this makes the guarantee whole. A claim code and a receiver code are
     * pasted into different fields and could not actually be confused, but
     * "your code is yours" is a rule that is much easier to trust when it has
     * no exceptions, and one extra indexed lookup is a cheap way to have none.
     *
     * @throws ShareException when no free code turns up, which should not happen
     */
    private function mintUniqueCode(): string
    {
        for ($attempt = 0; $attempt < 5; ++$attempt) {
            $candidate = SharingCodeFormat::generate();

            $takenByClaimCode = $this->claimCodeRepository
                ->findOneBy(['codeHash' => SharingCodeFormat::hash($candidate)]) !== null;
            $takenByReceiver = $this->userRepository
                ->findOneBy(['sharingCode' => $candidate]) !== null;

            if (!$takenByClaimCode && !$takenByReceiver) {
                return $candidate;
            }
        }

        throw new ShareException('A sharing code could not be created. Please try again.', 500);
    }

    /** @return list<ShareClaimCode> */
    public function codesFor(User $owner): array
    {
        return $this->claimCodeRepository->findForOwner($owner);
    }

    /**
     * Withdraw a code. The shares it already produced are untouched — they are
     * ordinary relationships now, revoked from the Sharing page like any other.
     *
     * @throws ShareException
     */
    public function revoke(int $codeId, User $owner): void
    {
        $code = $this->claimCodeRepository->find($codeId);

        // Reported as missing rather than forbidden, so somebody cannot learn
        // that an id belongs to somebody else's code.
        if ($code === null || $code->getOwner()?->getId() !== $owner->getId()) {
            throw new ShareException('That sharing code was not found.', 404);
        }

        $code->revoke();
        $this->entityManager->flush();

        $this->auditLogger->audit(SecurityAuditLogger::SHARE_CLAIM_CODE_REVOKED, [
            'actor_user_id' => $owner->getId(),
            'target_type' => 'share_claim_code',
            'target_id' => $code->getId(),
        ]);
    }

    /**
     * @throws ShareException
     */
    private function reserveIssueAllowance(User $owner): void
    {
        $limit = $this->shareClaimCodeLimiter->create((string) $owner->getId())->consume();

        if ($limit->isAccepted()) {
            return;
        }

        $retryAfter = max(1, $limit->getRetryAfter()->getTimestamp() - time());

        throw new ShareException(
            sprintf(
                'You have created too many sharing codes recently. Please try again in %d minute(s).',
                (int) ceil($retryAfter / 60)
            ),
            429
        );
    }

    /**
     * @throws ShareException always
     */
    private function rejectRedemption(User $redeemer, ?int $codeId): never
    {
        // Charged against the same allowance a mistyped receiver code is, so
        // redemption cannot be used to work through the keyspace either. The
        // throw it raises when the allowance runs out is a 429, which is a
        // truthful answer and still says nothing about the code that was tried.
        $this->sharingCodes->resolve('', $redeemer);

        $this->auditLogger->suspicious(
            SecurityAuditLogger::SHARE_CLAIM_CODE_REJECTED,
            'user:' . $redeemer->getId(),
            [
                'actor_user_id' => $redeemer->getId(),
                'target_type' => 'share_claim_code',
                'target_id' => $codeId,
            ],
            10
        );

        throw new ShareException('That sharing code is not valid, or has already been used up.', 404);
    }
}
