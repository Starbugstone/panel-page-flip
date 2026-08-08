<?php

namespace App\Service;

use App\Entity\AdminAuditLog;
use App\Entity\EmailVerificationToken;
use App\Entity\ResetPasswordToken;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;

final class PersonalDataRetentionService
{
    public function __construct(
        private readonly ManagerRegistry $managerRegistry,
        private readonly AccountDeletionService $accountDeletion,
        private readonly PendingFileDeletionService $pendingFileDeletion,
        private readonly LoggerInterface $logger,
        private readonly SecurityAuditLogger $auditLogger,
    ) {
    }

    /**
     * Unresolved file deletions above this many are no longer a retry that will
     * sort itself out. Something is holding those files — a permission, a mount,
     * a path that moved — and personal data is sitting on disk that this
     * application has promised to remove.
     */
    private const PENDING_FILE_ALERT_THRESHOLD = 25;

    /**
     * @return array{auditLogs: int, verificationTokens: int, resetTokens: int, unverifiedAccounts: int, filesDeleted: int, filesRemaining: int, errors: int}
     */
    public function clean(\DateTimeImmutable $now = new \DateTimeImmutable()): array
    {
        $entityManager = $this->entityManager();
        $counts = [
            'auditLogs' => $this->deleteOlderThan(AdminAuditLog::class, 'createdAt', $now->modify('-12 months')),
            'verificationTokens' => $this->deleteOlderThan(EmailVerificationToken::class, 'expiresAt', $now),
            'resetTokens' => $this->deleteOlderThan(ResetPasswordToken::class, 'expiresAt', $now),
            'unverifiedAccounts' => 0,
            'filesDeleted' => 0,
            'filesRemaining' => 0,
            'errors' => 0,
        ];
        $entityManager->flush();

        $staleUserIds = $entityManager->getRepository(User::class)
            ->createQueryBuilder('user')
            ->select('user.id')
            ->where('user.isEmailVerified = :verified')
            ->andWhere('user.createdAt < :cutoff')
            ->setParameter('verified', false)
            ->setParameter('cutoff', $now->modify('-30 days'))
            ->getQuery()
            ->getSingleColumnResult();

        foreach ($staleUserIds as $userId) {
            $user = $this->entityManager()->find(User::class, $userId);
            if ($user === null || in_array('ROLE_ADMIN', $user->getRoles(), true)) {
                continue;
            }

            try {
                // No actor: nobody asked for this one, the retention policy did.
                $this->accountDeletion->delete($user);
                ++$counts['unverifiedAccounts'];
            } catch (\Throwable $exception) {
                ++$counts['errors'];
                $this->logger->error('Failed to remove a stale unverified account.', [
                    'user_id' => $userId,
                    'exception' => $exception,
                ]);
                $this->resetEntityManager();
            }
        }

        $fileDeletionResult = $this->pendingFileDeletion->retryAll();
        $counts['filesDeleted'] = $fileDeletionResult['deleted'];
        $counts['filesRemaining'] = $fileDeletionResult['remaining'];

        // One record for the run. The database rows this deleted are counts, not
        // people: the accounts it removed are already recorded individually by
        // AccountDeletionService.
        $this->auditLogger->audit(SecurityAuditLogger::RETENTION_CLEANUP, [
            'target_type' => 'retention',
            'audit_logs_deleted' => $counts['auditLogs'],
            'verification_tokens_deleted' => $counts['verificationTokens'],
            'reset_tokens_deleted' => $counts['resetTokens'],
            'unverified_accounts_deleted' => $counts['unverifiedAccounts'],
            'files_deleted' => $counts['filesDeleted'],
            'files_remaining' => $counts['filesRemaining'],
            'errors' => $counts['errors'],
        ]);

        // A cleanup that keeps failing is a retention promise that is not being
        // kept, and nothing about it is visible from the application.
        if ($counts['errors'] > 0 || $counts['filesRemaining'] >= self::PENDING_FILE_ALERT_THRESHOLD) {
            $this->auditLogger->critical(
                SecurityAuditLogger::DATA_INTEGRITY_FAILURE,
                [
                    'target_type' => 'retention',
                    'operation' => 'personal_data_retention',
                    'errors' => $counts['errors'],
                    'files_remaining' => $counts['filesRemaining'],
                    'reason' => 'retention cleanup did not complete',
                ],
                SecurityAuditLogger::RESULT_FAILED,
                'retention'
            );
        }

        return $counts;
    }

    /**
     * @param class-string $entity
     */
    private function deleteOlderThan(string $entity, string $field, \DateTimeImmutable $cutoff): int
    {
        return $this->entityManager()->createQueryBuilder()
            ->delete($entity, 'item')
            ->where(sprintf('item.%s < :cutoff', $field))
            ->setParameter('cutoff', $cutoff)
            ->getQuery()
            ->execute();
    }

    private function entityManager(): EntityManagerInterface
    {
        $entityManager = $this->managerRegistry->getManager();
        if (!$entityManager instanceof EntityManagerInterface) {
            throw new \LogicException('Expected a Doctrine ORM entity manager.');
        }

        return $entityManager;
    }

    private function resetEntityManager(): void
    {
        if (!$this->entityManager()->isOpen()) {
            $this->managerRegistry->resetManager();
        }
    }
}
