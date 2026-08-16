<?php

namespace App\Service;

use App\Entity\Comic;
use App\Entity\ShareClaimCode;
use App\Entity\ShareClaimCodeRedemption;
use App\Entity\User;
use App\Enum\ShareCodeType;
use App\Repository\ShareClaimCodeRedemptionRepository;
use App\Repository\ShareClaimCodeRepository;
use App\Security\Voter\ComicVoter;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * Codes an owner hands out so somebody can come and get the comics behind them.
 *
 * The other half of the sharing-code story. A `U-` user code says "this is me";
 * these say "these are mine, take them" — for the case where the owner does not
 * know, and should not have to ask for, the other person's address.
 *
 * Two kinds, one lifecycle. A `C-` code is exactly one comic; a `G-` code is a
 * deliberate package of two to twenty, handed over whole or not at all. Which
 * one somebody is holding is legible from the code itself, which is the point
 * of the prefix.
 *
 * The code is a capability, so the guard rails are on the code and not on the
 * access it produces:
 *
 * - it is stored as a hash, so a database leak yields nothing redeemable
 * - it dies after the operator's configured lifetime, seven days by default,
 *   because a code pasted into a group chat is out of its owner's hands the
 *   moment it is sent
 * - it is spent as it is used, between one and ten times, so the owner decides
 *   up front how far it may travel — and a group costs one use however many
 *   comics it carries, because the recipient took the offer up once
 * - it grants nothing on its own. Redeeming requires being signed in, and
 *   produces the same ordinary {@see \App\Entity\ComicShare} an emailed
 *   invitation would
 *
 * That last point is the important one. Nothing downstream of redemption knows
 * or cares that a code was involved: the 18+ gate, revocation, tombstones and
 * the recipient's own housekeeping all behave exactly as they always have.
 */
final class ShareClaimCodeService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ShareClaimCodeRepository $contentCodeRepository,
        private readonly ShareClaimCodeRedemptionRepository $redemptionRepository,
        private readonly ComicShareService $shareService,
        private readonly AuthorizationCheckerInterface $authorizationChecker,
        private readonly SecurityAuditLogger $auditLogger,
        private readonly SharingCodeService $sharingCodes,
        private readonly ShareContentCodeLifetime $lifetime,
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
        ShareCodeType $type,
        int $maxUses,
        bool $senderResponsibilityAccepted
    ): array {
        if (!$type->isContentCode()) {
            throw new ShareException('A user code does not carry comics.', 400);
        }

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

        $ids = array_values(array_unique($comicIds));

        // Before the lookups, not after, so a request for the wrong shape does
        // not do a hundred queries on its way to being refused. This is the
        // invariant the prefix promises, so it is checked on the count the
        // caller asked for rather than on whatever survives authorization.
        if (!ShareClaimCode::isUsableComicCount($type, count($ids))) {
            throw new ShareException(ShareClaimCode::describeComicCountProblem($type), 400);
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

        // Claimed before the code exists, so a refused request leaves nothing
        // behind. Creating a code sends no mail, which is why this is its own
        // allowance rather than the invitation limiter's.
        $this->reserveIssueAllowance($owner);

        // Both kinds of code come out of the same allocator, so one visible
        // token always means one thing.
        $plaintext = $this->sharingCodes->allocateUniqueCode();
        $code = new ShareClaimCode(
            $owner,
            $type,
            SharingCodeFormat::hash($type, $plaintext),
            $comics,
            $maxUses,
            $this->lifetime->expiryFrom()
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
            'target_type' => 'share_content_code',
            'target_id' => $code->getId(),
            // Ids, counts and the type letter only. The plaintext is never
            // written anywhere, and the title of an explicit comic is the thing
            // the gate withholds.
            'code_type' => $type->value,
            'comic_ids' => array_map(static fn (Comic $comic): ?int => $comic->getId(), $comics),
            'max_uses' => $maxUses,
            'expires_at' => $code->getExpiresAt()->format(DATE_ATOM),
        ]);

        return ['code' => $code, 'plaintext' => $plaintext];
    }

    /**
     * Turn a code somebody was given into shares addressed to them.
     *
     * The type they typed is the type that is looked up. A `U-` code pasted
     * here is not a failed guess — it is a real code in the wrong box, and the
     * holder is told where it goes rather than told it is invalid.
     *
     * @return array{results: list<array<string, mixed>>, claimed: int, alreadyHeld: int,
     *               alreadyRedeemed: bool, type: string, ownerLabel: string}
     *
     * @throws ShareException
     */
    public function redeem(string $plaintext, User $redeemer): array
    {
        $parsed = SharingCodeFormat::parse($plaintext);

        if ($parsed !== null && !$parsed->type->isContentCode()) {
            throw new ShareException(
                $parsed->type->misuseGuidance(),
                400,
                ShareException::CODE_WRONG_CODE_TYPE
            );
        }

        $code = $parsed === null ? null : $this->contentCodeRepository->findByParsedCode($parsed);

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
     * @return array{results: list<array<string, mixed>>, claimed: int, alreadyHeld: int,
     *               alreadyRedeemed: bool, type: string, ownerLabel: string}
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
        // one that ran out, one that expired and one whose package is no longer
        // whole. Telling them apart would say whether a guess had ever been a
        // real code.
        if (!$code->isRedeemable()) {
            $this->rejectRedemption($redeemer, $code->getId());
        }

        $owner = $code->getOwner();

        if ($owner === null || $owner->getId() === $redeemer->getId()) {
            // Redeeming your own code is not an attack, and saying so plainly
            // reveals nothing the holder does not already know.
            throw new ShareException('This is your own sharing code.', 409);
        }

        // One account, at most one use. Without this a recipient could submit
        // the same code ten times and exhaust an offer advertised to ten
        // people — and the owner's "claimed 10 of 10" would be counting
        // requests rather than the audience it says it counts.
        $alreadyRedeemed = $this->redemptionRepository->findFor($code, $redeemer) !== null;

        // The whole package is judged before any of it is handed over. A group
        // is an offer of an arc, and a recipient who is given eleven issues of
        // fifteen without being told has been given something nobody offered
        // them — so a comic that has become unshareable since the code was
        // minted kills the redemption rather than shrinking it. The use is not
        // spent: they took up nothing.
        $comics = [];
        foreach ($code->getComics() as $comic) {
            if (!$this->shareService->isShareableBy($comic, $owner)) {
                throw new ShareException(
                    $code->getType() === ShareCodeType::GROUP
                        ? 'Some of the comics in this group are no longer available, so nothing was added. Ask the owner for a new code.'
                        : 'That comic is no longer available to share.',
                    409
                );
            }

            $comics[] = $comic;
        }

        $results = [];
        $claimed = 0;
        $alreadyHeld = 0;

        foreach ($comics as $comic) {
            // The share lifecycle belongs to ComicShareService, wherever a
            // share comes from. A transport that grew its own copy of these
            // transitions would drift from the canonical rules.
            $outcome = $this->shareService->claimFromCode(
                $comic,
                $owner,
                $redeemer,
                // The owner acknowledged responsibility when they created the
                // code, not now — and the field this lands in is the canonical
                // evidence of when they did.
                $code->getSenderResponsibilityAcceptedAt()
            );

            $results[] = ['comicId' => (int) $comic->getId()] + $outcome;

            if ($outcome['status'] === 'already_yours') {
                ++$alreadyHeld;
            } else {
                ++$claimed;
            }
        }

        // Spent only for an account that did not already hold a use. Repeating
        // the same redemption is idempotent: it re-reports what they have and
        // takes nothing from anybody else.
        if (!$alreadyRedeemed) {
            $this->entityManager->persist(new ShareClaimCodeRedemption($code, $redeemer));
            $code->spendUse();
        }

        $this->entityManager->flush();

        $this->auditLogger->audit(SecurityAuditLogger::SHARE_CLAIM_CODE_REDEEMED, [
            'actor_user_id' => $redeemer->getId(),
            'target_type' => 'share_content_code',
            'target_id' => $code->getId(),
            'code_type' => $code->getType()->value,
            'owner_user_id' => $owner->getId(),
            'claimed' => $claimed,
            'repeat' => $alreadyRedeemed,
            'uses_remaining' => $code->getUsesRemaining(),
        ]);

        return [
            'results' => $results,
            // What arrived now and what they already had, told apart: a group
            // that overlaps a recipient's existing shares is reused rather than
            // duplicated, and the answer should say which was which.
            'claimed' => $claimed,
            'alreadyHeld' => $alreadyHeld,
            'alreadyRedeemed' => $alreadyRedeemed,
            'type' => $code->getType()->value,
            // The owner as the redeemer may see them: their public identity,
            // never their address. A code says nothing about who issued it, so
            // redeeming one must not become a way to learn that.
            'ownerLabel' => $this->sharingCodes->describe($owner)['label'],
        ];
    }

    /** @return list<ShareClaimCode> */
    public function codesFor(User $owner): array
    {
        return $this->contentCodeRepository->findForOwner($owner);
    }

    /**
     * Withdraw a code. The shares it already produced are untouched — they are
     * ordinary relationships now, revoked from the Sharing page like any other.
     *
     * @throws ShareException
     */
    public function revoke(int $codeId, User $owner): void
    {
        $code = $this->contentCodeRepository->find($codeId);

        // Reported as missing rather than forbidden, so somebody cannot learn
        // that an id belongs to somebody else's code.
        if ($code === null || $code->getOwner()?->getId() !== $owner->getId()) {
            throw new ShareException('That sharing code was not found.', 404);
        }

        $this->withdraw($code, $owner);
    }

    /**
     * Withdraw somebody else's code, as an administrator.
     *
     * The reasons are operational rather than the owner changing their mind: an
     * abuse report, a code posted publicly, a compromised account. It stops the
     * code and nothing else — the shares already made through it stay exactly
     * as they are, which is the same rule that applies when the owner withdraws
     * it themselves. Taking those away would be moderation of the comics, which
     * is a different decision with a different surface.
     *
     * @throws ShareException
     */
    public function revokeAsAdministrator(int $codeId, User $admin): ShareClaimCode
    {
        $code = $this->contentCodeRepository->find($codeId);

        if ($code === null) {
            throw new ShareException('That sharing code was not found.', 404);
        }

        $this->withdraw($code, $admin);

        return $code;
    }

    /**
     * The lifecycle rule itself, wherever the request came from.
     *
     * Both callers land here so an administrative path cannot grow its own idea
     * of what withdrawing means.
     */
    private function withdraw(ShareClaimCode $code, User $actor): void
    {
        $ownerId = $code->getOwner()?->getId();
        $byAdmin = $ownerId !== $actor->getId();

        $code->revoke();
        $this->entityManager->flush();

        $this->auditLogger->audit(SecurityAuditLogger::SHARE_CLAIM_CODE_REVOKED, [
            'actor_user_id' => $actor->getId(),
            'target_type' => 'share_content_code',
            'target_id' => $code->getId(),
            'code_type' => $code->getType()->value,
            'owner_user_id' => $ownerId,
            'by_admin' => $byAdmin,
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
        // Charged against the same allowance a mistyped user code is, so
        // redemption cannot be used to work through the keyspace either. The
        // throw it raises when the allowance runs out is a 429, which is a
        // truthful answer and still says nothing about the code that was tried.
        $this->sharingCodes->chargeFailedLookup($redeemer);

        $this->auditLogger->suspicious(
            SecurityAuditLogger::SHARE_CLAIM_CODE_REJECTED,
            'user:' . $redeemer->getId(),
            [
                'actor_user_id' => $redeemer->getId(),
                'target_type' => 'share_content_code',
                'target_id' => $codeId,
            ],
            10
        );

        throw new ShareException('This sharing code is unavailable or no longer valid.', 404);
    }
}
