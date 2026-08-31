<?php

namespace App\Service;

use App\Entity\ComicShare;
use App\Entity\ShareClaimCode;
use App\Entity\User;
use App\Repository\ComicShareRepository;
use App\Repository\ShareClaimCodeRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The retention sweep for sharing records, owned in one place.
 *
 * Two callers run this: the scheduled `app:cleanup-expired-shares` command,
 * which is the normal path, and an administrator pressing a button, which is
 * the fallback for an installation whose cron is not running. They share the
 * code rather than the intent — a deletion rule that exists twice is a deletion
 * rule that will eventually disagree with itself, and this one decides what to
 * remove from somebody's account.
 *
 * What it will never do, however it is called:
 *
 * - touch a live invitation, a live code or a live share. Only records past
 *   their own deadline are in scope, and pressing the button early deletes
 *   nothing
 * - treat a {@see \App\Entity\ComicShare} created by redeeming a code as part
 *   of the code. Those are ordinary relationships and outlive the code
 *   entirely; a code is a way in, never the access itself. A share only
 *   becomes sweepable by being revoked and staying revoked past its own
 *   retention window, however it began
 */
final class ExpiredShareCleanupService
{
    /**
     * Records removed per flush. A sweep that has not run for a long time would
     * otherwise hydrate every expired record and its cascaded rows into one
     * unit of work — and run out of memory before deleting anything at all.
     */
    public const BATCH_SIZE = 200;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ComicShareRepository $shareRepository,
        private readonly ShareClaimCodeRepository $claimCodeRepository,
        private readonly SecurityAuditLogger $auditLogger,
    ) {
    }

    /**
     * Both sweeps, with one clock.
     *
     * `$now` is resolved by the caller and passed down so a long backlog cannot
     * pick up records that expire while it is still working through the first
     * ones.
     *
     * @return array{invitations: int, claimCodes: int, revokedShares: int}
     */
    public function run(?\DateTimeImmutable $now = null): array
    {
        $now ??= new \DateTimeImmutable();

        return [
            'invitations' => $this->cleanupExpiredInvitations($now),
            'claimCodes' => $this->cleanupExpiredClaimCodes($now),
            'revokedShares' => $this->cleanupRevokedShares($now),
        ];
    }

    /**
     * Delete invitations nobody answered before they expired.
     *
     * Only pending relationships are in scope. An accepted share has no
     * expiry, a declined one is history somebody may still be looking at, and
     * a revoked one has its own sweep with its own clock. An expired
     * invitation is deleted rather than kept because it holds the email
     * address of somebody who may never have had an account here and who did
     * not act on it.
     */
    public function cleanupExpiredInvitations(?\DateTimeImmutable $now = null): int
    {
        $now ??= new \DateTimeImmutable();
        $count = 0;

        while (($expired = $this->shareRepository->findExpiredPendingShares($now, self::BATCH_SIZE)) !== []) {
            foreach ($expired as $share) {
                // The invitation tokens go with it: they are mapped with
                // orphanRemoval and a cascading foreign key.
                $this->entityManager->remove($share);
            }

            $this->entityManager->flush();
            $this->entityManager->clear();
            $count += count($expired);
        }

        return $count;
    }

    /**
     * Delete codes that died long enough ago to have answered every question
     * their owner had about them.
     *
     * Withdrawn codes go the same way and on the same clock. Withdrawing one
     * stops it working immediately; it does not make the record of what was
     * offered any less interesting to the person who offered it.
     *
     * Only the code rows go, with their comic links and redemption history.
     * The shares a code produced are ordinary relationships and are not in
     * scope here at all.
     */
    public function cleanupExpiredClaimCodes(?\DateTimeImmutable $now = null): int
    {
        $now ??= new \DateTimeImmutable();
        $count = 0;

        while (($dead = $this->claimCodeRepository->findDeletable($now, self::BATCH_SIZE)) !== []) {
            foreach ($dead as $code) {
                $this->entityManager->remove($code);
            }

            $this->entityManager->flush();
            $this->entityManager->clear();
            $count += count($dead);
        }

        return $count;
    }

    /**
     * Delete revoked shares whose retention window has passed.
     *
     * A revocation is acted on the moment it is made; the row afterwards is
     * only the record of it, kept for {@see ComicShare::RETENTION_AFTER_REVOCATION}
     * so the owner can still see who they cut off and the recipient can still
     * read why a comic went away. After that it is a dead relationship in two
     * people's lists, and it goes the way a dead sharing code does.
     */
    public function cleanupRevokedShares(?\DateTimeImmutable $now = null): int
    {
        $now ??= new \DateTimeImmutable();
        $count = 0;

        while (($revoked = $this->shareRepository->findRevokedDeletable($now, self::BATCH_SIZE)) !== []) {
            foreach ($revoked as $share) {
                $this->entityManager->remove($share);
            }

            $this->entityManager->flush();
            $this->entityManager->clear();
            $count += count($revoked);
        }

        return $count;
    }

    /**
     * The same sweep, run by hand, with a record of who ran it.
     *
     * The scheduled command is deliberately quiet — a cron job reporting "0
     * removed" every hour is noise, and its work is already visible in what is
     * no longer there. Somebody pressing a button is not: an administrator
     * deleting records from other people's accounts is exactly the kind of
     * thing that should be answerable afterwards, and the count is what makes
     * it answerable.
     *
     * @return array{invitations: int, claimCodes: int, revokedShares: int}
     */
    public function runForAdministrator(User $admin): array
    {
        $removed = $this->run();

        $this->auditLogger->audit(SecurityAuditLogger::RETENTION_CLEANUP, [
            'actor_user_id' => $admin->getId(),
            'target_type' => 'share',
            'scope' => 'manual_admin_sweep',
            'invitations_removed' => $removed['invitations'],
            'claim_codes_removed' => $removed['claimCodes'],
            'revoked_shares_removed' => $removed['revokedShares'],
            'retention_after_expiry' => ShareClaimCode::RETENTION_AFTER_EXPIRY,
            'retention_after_revocation' => ComicShare::RETENTION_AFTER_REVOCATION,
        ]);

        return $removed;
    }
}
