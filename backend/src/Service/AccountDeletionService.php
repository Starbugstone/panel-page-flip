<?php

namespace App\Service;

use App\Entity\AdminAuditLog;
use App\Entity\ComicShare;
use App\Entity\User;
use App\Repository\ComicShareRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;

final class AccountDeletionService
{
    public function __construct(
        private readonly ManagerRegistry $managerRegistry,
        private readonly ComicService $comicService,
        private readonly ComicShareService $shareService,
        private readonly ComicShareRepository $shareRepository,
        private readonly FileQuarantineService $fileQuarantine,
        private readonly PendingFileDeletionService $pendingFileDeletion,
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

    private function anonymiseAuditHistory(User $user): void
    {
        $email = (string) $user->getEmail();
        $name = $user->getName();

        foreach ($this->entityManager()->getRepository(AdminAuditLog::class)->findAll() as $log) {
            if ($log->getAdminUser()?->getId() === $user->getId()) {
                $log->setAdminUser(null);
            }

            if ($log->getTargetType() === 'user' && $log->getTargetId() === $user->getId()) {
                $log->setTargetId(null);
            }

            $log->setPayload($this->redactPayload($log->getPayload(), $email, $name));
        }
    }

    /**
     * @param array<mixed>|null $payload
     * @return array<mixed>|null
     */
    private function redactPayload(?array $payload, string $email, ?string $name): ?array
    {
        if ($payload === null) {
            return null;
        }

        array_walk_recursive($payload, static function (&$value) use ($email, $name): void {
            if (!is_string($value)) {
                return;
            }
            $value = str_ireplace($email, '[redacted]', $value);
            if ($name !== null && $name !== '') {
                $value = str_ireplace($name, '[redacted]', $value);
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
