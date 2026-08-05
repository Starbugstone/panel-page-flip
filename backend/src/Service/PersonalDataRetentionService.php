<?php

namespace App\Service;

use App\Entity\AdminAuditLog;
use App\Entity\EmailVerificationToken;
use App\Entity\ResetPasswordToken;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

final class PersonalDataRetentionService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AccountDeletionService $accountDeletion,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @return array{auditLogs: int, verificationTokens: int, resetTokens: int, unverifiedAccounts: int, errors: int}
     */
    public function clean(\DateTimeImmutable $now = new \DateTimeImmutable()): array
    {
        $counts = [
            'auditLogs' => $this->deleteOlderThan(AdminAuditLog::class, 'createdAt', $now->modify('-12 months')),
            'verificationTokens' => $this->deleteOlderThan(EmailVerificationToken::class, 'expiresAt', $now),
            'resetTokens' => $this->deleteOlderThan(ResetPasswordToken::class, 'expiresAt', $now),
            'unverifiedAccounts' => 0,
            'errors' => 0,
        ];
        $this->entityManager->flush();

        $staleUsers = $this->entityManager->getRepository(User::class)
            ->createQueryBuilder('user')
            ->where('user.isEmailVerified = :verified')
            ->andWhere('user.createdAt < :cutoff')
            ->setParameter('verified', false)
            ->setParameter('cutoff', $now->modify('-30 days'))
            ->getQuery()
            ->getResult();

        foreach ($staleUsers as $user) {
            if (in_array('ROLE_ADMIN', $user->getRoles(), true)) {
                continue;
            }

            try {
                $this->accountDeletion->delete($user);
                ++$counts['unverifiedAccounts'];
            } catch (\Throwable $exception) {
                ++$counts['errors'];
                $this->logger->error('Failed to remove a stale unverified account.', [
                    'user_id' => $user->getId(),
                    'exception' => $exception,
                ]);
            }
        }

        return $counts;
    }

    /**
     * @param class-string $entity
     */
    private function deleteOlderThan(string $entity, string $field, \DateTimeImmutable $cutoff): int
    {
        return $this->entityManager->createQueryBuilder()
            ->delete($entity, 'item')
            ->where(sprintf('item.%s < :cutoff', $field))
            ->setParameter('cutoff', $cutoff)
            ->getQuery()
            ->execute();
    }
}
