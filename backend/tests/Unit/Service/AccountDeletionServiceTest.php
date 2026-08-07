<?php

namespace App\Tests\Unit\Service;

use App\Entity\AdminAuditLog;
use App\Entity\User;
use App\Repository\ComicShareRepository;
use App\Repository\UserRepository;
use App\Service\AccountDeletionService;
use App\Service\ComicService;
use App\Service\ComicShareService;
use App\Service\FileQuarantineService;
use App\Service\PendingFileDeletionService;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * delete() must not issue a real commit/rollback when the caller already owns
 * the transaction (e.g. DAMA's per-test wrap, or a future caller that batches
 * several deletions). Doctrine's own transaction nesting makes an unconditional
 * begin/commit *look* harmless, but DAMA's StaticConnection only absorbs the
 * first begin per connection and does not override commit() at all, so a
 * second top-level begin+commit pair inside a test would issue a real COMMIT
 * against the test database and escape the end-of-test rollback. These tests
 * pin the ownership decision directly against a mocked Connection so a
 * regression here is caught without needing to reproduce that leak for real.
 */
final class AccountDeletionServiceTest extends TestCase
{
    public function testOwnsAndCommitsItsOwnTransactionWhenNoneIsActive(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())->method('isTransactionActive')->willReturn(false);

        $entityManager = $this->makeEntityManager($connection);
        $entityManager->expects(self::once())->method('beginTransaction');
        $entityManager->expects(self::once())->method('commit');

        $service = $this->makeService($entityManager);

        $service->delete($this->makeUser());
    }

    public function testDoesNotBeginOrCommitWhenCallerAlreadyOwnsTheTransaction(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())->method('isTransactionActive')->willReturn(true);

        $entityManager = $this->makeEntityManager($connection);
        $entityManager->expects(self::never())->method('beginTransaction');
        $entityManager->expects(self::never())->method('commit');
        $entityManager->expects(self::once())->method('flush');

        $service = $this->makeService($entityManager);

        $service->delete($this->makeUser());
    }

    public function testRollsBackAndRestoresFilesWhenItOwnsTheTransactionAndFlushFails(): void
    {
        $connection = $this->createMock(Connection::class);
        // Called twice: once to decide ownership (no transaction yet), once
        // inside the catch block to confirm the transaction it opened is
        // still there to roll back.
        $connection->expects(self::exactly(2))->method('isTransactionActive')
            ->willReturnOnConsecutiveCalls(false, true);

        $entityManager = $this->makeEntityManager($connection);
        $entityManager->expects(self::once())->method('beginTransaction');
        $entityManager->expects(self::never())->method('commit');
        $entityManager->method('flush')->willThrowException(new \RuntimeException('database exploded'));
        $entityManager->expects(self::once())->method('rollback');

        $fileQuarantine = $this->createMock(FileQuarantineService::class);
        $fileQuarantine->expects(self::once())->method('restore')->with([]);

        $service = $this->makeService($entityManager, fileQuarantine: $fileQuarantine);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('database exploded');

        $service->delete($this->makeUser());
    }

    public function testDoesNotRollBackWhenItDoesNotOwnTheTransactionAndFlushFails(): void
    {
        $connection = $this->createMock(Connection::class);
        // Called once for the ownership decision only; the short-circuited
        // catch-block condition must not re-check it when ownsTransaction is
        // false, since rolling back here would be rolling back the caller's
        // transaction out from under it.
        $connection->expects(self::once())->method('isTransactionActive')->willReturn(true);

        $entityManager = $this->makeEntityManager($connection);
        $entityManager->expects(self::never())->method('beginTransaction');
        $entityManager->expects(self::never())->method('commit');
        $entityManager->method('flush')->willThrowException(new \RuntimeException('database exploded'));
        $entityManager->expects(self::never())->method('rollback');

        $fileQuarantine = $this->createMock(FileQuarantineService::class);
        $fileQuarantine->expects(self::once())->method('restore')->with([]);

        $service = $this->makeService($entityManager, fileQuarantine: $fileQuarantine);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('database exploded');

        $service->delete($this->makeUser());
    }

    private function makeUser(): User
    {
        $user = new User();
        $user->setEmail('erase-me@test.local');
        $user->setName('Erase Me');

        return $user;
    }

    /**
     * @return EntityManagerInterface&MockObject
     */
    private function makeEntityManager(Connection $connection): MockObject
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getConnection')->willReturn($connection);

        $query = $this->createMock(Query::class);
        $query->method('getResult')->willReturn([]);

        $queryBuilder = $this->createMock(QueryBuilder::class);
        $queryBuilder->method('select')->willReturnSelf();
        $queryBuilder->method('from')->willReturnSelf();
        $queryBuilder->method('join')->willReturnSelf();
        $queryBuilder->method('where')->willReturnSelf();
        $queryBuilder->method('orWhere')->willReturnSelf();
        $queryBuilder->method('setParameter')->willReturnSelf();
        $queryBuilder->method('getQuery')->willReturn($query);
        $entityManager->method('createQueryBuilder')->willReturn($queryBuilder);

        $auditLogRepository = $this->createMock(EntityRepository::class);
        $auditLogRepository->method('findAll')->willReturn([]);
        $entityManager->method('getRepository')->with(AdminAuditLog::class)->willReturn($auditLogRepository);

        return $entityManager;
    }

    private function makeService(
        EntityManagerInterface $entityManager,
        ?FileQuarantineService $fileQuarantine = null,
    ): AccountDeletionService {
        $managerRegistry = $this->createMock(ManagerRegistry::class);
        $managerRegistry->method('getManager')->willReturn($entityManager);

        // PendingFileDeletionService is final and cannot be mocked; every
        // scenario here deletes a user with no comics, shares, or tags, so
        // queue() always receives an empty path list and cancel()/purge()
        // are no-ops on it regardless of $ownsTransaction — a real instance
        // is simpler than faking that interaction.
        $pendingFileDeletion = new PendingFileDeletionService($managerRegistry, [], new NullLogger());

        $shareRepository = $this->createMock(ComicShareRepository::class);
        $shareRepository->method('findAllInvolving')->willReturn([]);

        return new AccountDeletionService(
            $managerRegistry,
            $this->createMock(ComicService::class),
            $this->createMock(ComicShareService::class),
            $shareRepository,
            $fileQuarantine ?? $this->createMock(FileQuarantineService::class),
            $pendingFileDeletion,
            $this->createMock(UserRepository::class),
        );
    }
}
