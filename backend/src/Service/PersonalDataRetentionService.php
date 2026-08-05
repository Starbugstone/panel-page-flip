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
    ) {
    }

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
