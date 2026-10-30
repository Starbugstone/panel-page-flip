<?php

namespace App\Service;

use App\Entity\AdminAuditLog;
use App\Entity\ComicShare;
use App\Entity\User;
use App\Repository\ComicShareRepository;
use App\Repository\UserRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;

final class AccountDeletionService
{
    /** Audit rows read at a time, so one erasure never holds the whole table. */
    private const AUDIT_BATCH_SIZE = 500;

    private const REDACTED = '[redacted]';

    public function __construct(
        private readonly ManagerRegistry $managerRegistry,
        private readonly ComicService $comicService,
        private readonly ComicShareService $shareService,
        private readonly ComicShareRepository $shareRepository,
        private readonly FileQuarantineService $fileQuarantine,
        private readonly PendingFileDeletionService $pendingFileDeletion,
        private readonly ComicUploadStagingService $uploadStaging,
        private readonly UserRepository $userRepository,
        private readonly SecurityAuditLogger $auditLogger,
    ) {
    }

    /**
     * @param User|null $actor who asked for this — the account holder, an
     *                         administrator, or null for the retention sweep,
     *                         which acts on nobody's behalf
     */
    public function delete(User $user, ?User $actor = null): void
    {
        $this->erase($user, $actor);
    }

    /**
     * The retention sweep's way in. Its list of stale accounts is old news by
     * the time it reaches each one, so whether this account may still go is
     * decided here, on a locked row, inside the transaction that removes it.
     * A verification landing first keeps the account; one landing after waits
     * on the lock and finds it gone.
     *
     * @return bool false when the account no longer qualifies and was left alone
     */
    public function deleteIfStillUnverified(User $user, \DateTimeImmutable $createdBefore): bool
    {
        try {
            $this->erase($user, null, $createdBefore);
        } catch (AccountNoLongerEligible) {
            return false;
        }

        return true;
    }

    private function erase(User $user, ?User $actor, ?\DateTimeImmutable $unverifiedCreatedBefore = null): void
    {
        // Read before the removal, because afterwards the entity has no id and
        // an audit record that cannot name what it deleted is not one.
        //
        // The actor for the same reason, and not only the target: on the
        // self-service path the actor *is* the account being removed, so
        // reading its id after the flush would report the one deletion somebody
        // asked for themselves as having no actor at all — indistinguishable
        // from the retention sweep, which is the one case where nobody asked.
        $userId = $user->getId();
        $actorId = $actor?->getId();
        $wasAdmin = $user->isAdmin();

        $quarantinedFiles = [];
        $pendingFileDeletions = [];
        $tombstonedShares = 0;
        $entityManager = $this->entityManager();
        // Avoid nesting commits when a caller (or the test suite) already owns
        // the transaction — a nested commit would escape DAMA's rollback wrap.
        $ownsTransaction = !$entityManager->getConnection()->isTransactionActive();

        try {
            if ($ownsTransaction) {
                $entityManager->beginTransaction();
            }

            if ($unverifiedCreatedBefore !== null) {
                $this->assertStillUnverified($user, $unverifiedCreatedBefore);
            }

            if ($user->isAdmin()) {
                $this->userRepository->lockAdministrators();
                if ($this->userRepository->countAdminsExcluding($user) === 0) {
                    throw new \DomainException('The last administrator account cannot be deleted.');
                }
            }

            foreach ($user->getComics()->toArray() as $comic) {
                // Recipients of this comic are told it went away with its
                // owner's account rather than finding it silently missing.
                $tombstonedShares += $this->shareService->tombstoneSharesForComic($comic, ComicShare::REASON_OWNER_ACCOUNT_DELETED);
                array_push($quarantinedFiles, ...$this->comicService->quarantineComicFiles($comic));
            }

            foreach ($this->shareRepository->findAllInvolving($user) as $share) {
                // Anything addressed to this user holds their email address, so
                // it goes; the tombstones they left behind for other people stay
                // but stop naming them.
                if ($this->isRecipient($share, $user)) {
                    $entityManager->remove($share);
                    continue;
                }

                $share->anonymiseOwner();
            }

            foreach ($user->getCreatedTags()->toArray() as $tag) {
                foreach ($tag->getComics()->toArray() as $comic) {
                    $comic->removeTag($tag);
                }
                $entityManager->remove($tag);
            }

            $pendingFileDeletions = $this->pendingFileDeletion->queue(
                array_column($quarantinedFiles, 'quarantinePath')
            );
            $this->anonymiseAuditHistory($user);
            $entityManager->remove($user);
            $entityManager->flush();
            if ($ownsTransaction) {
                $entityManager->commit();
            }
        } catch (\Throwable $exception) {
            if ($ownsTransaction && $entityManager->getConnection()->isTransactionActive()) {
                $entityManager->rollback();
            }
            $this->pendingFileDeletion->cancel($pendingFileDeletions);
            $this->fileQuarantine->restore($quarantinedFiles);

            // A refused deletion — the last administrator, say — is the rule
            // working and needs no alarm. Anything else got part way through
            // moving somebody's files before failing, and even though the
            // rollback above puts them back, an administrator should know that
            // an erasure request did not complete.
            if (!$exception instanceof \DomainException) {
                $this->auditLogger->critical(
                    SecurityAuditLogger::DATA_INTEGRITY_FAILURE,
                    [
                        'target_user_id' => $userId,
                        'target_type' => 'user',
                        'operation' => 'account_deletion',
                        'reason' => 'account deletion failed after partial cleanup',
                        'quarantined_files' => count($quarantinedFiles),
                    ],
                    SecurityAuditLogger::RESULT_FAILED,
                    'user:' . $userId
                );
            }

            throw $exception;
        }

        if ($pendingFileDeletions !== []) {
            $this->pendingFileDeletion->purge($pendingFileDeletions);
        }

        $this->uploadStaging->removeForAccount((int) $userId);

        // Written once here, so self-service deletion, admin deletion and the
        // retention sweep all leave the same record. The address is gone by
        // now — that is the point of the operation — and the id is what the
        // remaining anonymised rows are keyed on anyway.
        $this->auditLogger->audit(SecurityAuditLogger::USER_ACCOUNT_DELETED, [
            'actor_user_id' => $actorId,
            'target_user_id' => $userId,
            'target_type' => 'user',
            'target_was_admin' => $wasAdmin,
            'self_service' => $actorId !== null && $actorId === $userId,
            'comics_quarantined' => count($quarantinedFiles),
            'shares_tombstoned' => $tombstonedShares,
        ]);
    }

    private function isRecipient(ComicShare $share, User $user): bool
    {
        return $share->getRecipientUser()?->getId() === $user->getId()
            || $share->getRecipientEmailNormalized() === ComicShare::normaliseEmail((string) $user->getEmail());
    }

    private function assertStillUnverified(User $user, \DateTimeImmutable $createdBefore): void
    {
        if ($this->userRepository->lockAndRefresh($user) === null) {
            throw new AccountNoLongerEligible('The account has already been removed.');
        }

        if ($user->isEmailVerified() || $user->isAdmin() || $user->getCreatedAt() >= $createdBefore) {
            throw new AccountNoLongerEligible('The account no longer qualifies for retention cleanup.');
        }
    }

    private function anonymiseAuditHistory(User $user): void
    {
        $entityManager = $this->entityManager();
        $userId = $user->getId();
        $email = (string) $user->getEmail();
        $name = $user->getName();

        $lastId = 0;
        do {
            $rows = $entityManager->createQueryBuilder()
                ->select('log.id', 'log.targetType', 'log.targetId', 'IDENTITY(log.adminUser) AS adminUserId', 'log.payload')
                ->from(AdminAuditLog::class, 'log')
                ->where('log.id > :lastId')
                ->setParameter('lastId', $lastId)
                ->orderBy('log.id', 'ASC')
                ->setMaxResults(self::AUDIT_BATCH_SIZE)
                ->getQuery()
                ->getArrayResult();

            foreach ($rows as $row) {
                $lastId = (int) $row['id'];
                if ($row['payload'] === null) {
                    continue;
                }

                $aboutUser = (int) $row['adminUserId'] === $userId
                    || ($row['targetType'] === 'user' && (int) $row['targetId'] === $userId);
                $redacted = $this->redactPayload($row['payload'], $email, $name, $aboutUser);
                if ($redacted === $row['payload']) {
                    continue;
                }

                $entityManager->createQueryBuilder()
                    ->update(AdminAuditLog::class, 'log')
                    ->set('log.payload', ':payload')
                    ->where('log.id = :id')
                    ->setParameter('payload', $redacted, Types::JSON)
                    ->setParameter('id', $lastId)
                    ->getQuery()
                    ->execute();
            }
        } while (count($rows) === self::AUDIT_BATCH_SIZE);

        // After the walk above, which needs these columns to tell whose row it
        // is reading.
        $entityManager->createQueryBuilder()
            ->update(AdminAuditLog::class, 'log')
            ->set('log.adminUser', 'NULL')
            ->where('log.adminUser = :userId')
            ->setParameter('userId', $userId)
            ->getQuery()
            ->execute();
        $entityManager->createQueryBuilder()
            ->update(AdminAuditLog::class, 'log')
            ->set('log.targetId', 'NULL')
            ->where('log.targetType = :targetType')
            ->andWhere('log.targetId = :userId')
            ->setParameter('targetType', 'user')
            ->setParameter('userId', $userId)
            ->getQuery()
            ->execute();
    }

    /**
     * An address is unique to its owner, so it goes wherever it appears. A
     * display name is not: anybody can register as `a`, `Stan Lee` or
     * `ROLE_USER`. Only a whole value can be taken for the name, and only in a
     * field that holds a person's name or on a row about this account, so that
     * one deletion can never rewrite a comic's author or a role in somebody
     * else's history.
     *
     * @param array<mixed> $payload
     * @return array<mixed>
     */
    private function redactPayload(array $payload, string $email, ?string $name, bool $aboutUser): array
    {
        // The lookarounds keep ann@example.com from matching inside
        // joann@example.community, and still allow for a sentence's full stop.
        $emailPattern = $email === ''
            ? null
            : '/(?<![\w.%+-])'.preg_quote($email, '/').'(?![\w%+-]|\.\w)/iu';
        $normalisedName = mb_strtolower(trim((string) $name));

        array_walk_recursive($payload, static function (&$value, $key) use ($emailPattern, $normalisedName, $aboutUser): void {
            if (!is_string($value)) {
                return;
            }
            if ($normalisedName !== ''
                && ($aboutUser || $key === 'name')
                && mb_strtolower(trim($value)) === $normalisedName
            ) {
                $value = self::REDACTED;

                return;
            }
            if ($emailPattern !== null) {
                // A value the pattern cannot be run over is not one to keep.
                $value = preg_replace($emailPattern, self::REDACTED, $value) ?? self::REDACTED;
            }
        });

        return $payload;
    }

    private function entityManager(): EntityManagerInterface
    {
        $entityManager = $this->managerRegistry->getManager();
        if (!$entityManager instanceof EntityManagerInterface) {
            throw new \LogicException('Expected a Doctrine ORM entity manager.');
        }

        return $entityManager;
    }
}
