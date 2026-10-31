<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\User;
use App\Repository\ResetPasswordTokenRepository;
use App\Repository\UserRepository;
use App\Service\AdminAuditService;
use App\Service\PasswordValidator;
use App\Service\SecurityAuditLogger;
use App\Service\UserUpdateRejected;
use App\Service\UserUpdateService;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * The race two administrators lose together can only be shown with two real
 * database sessions, which the per-test transaction rules out. What can be
 * pinned here is what makes it safe: the count happens after the lock, and the
 * lock is held inside a transaction until the demotion has committed.
 */
final class UserUpdateServiceTest extends TestCase
{
    /** @var list<string> */
    private array $events = [];

    public function testADemotionCountsUnderTheLockAndCommitsBeforeReleasingIt(): void
    {
        $this->service(remainingAdmins: 1)->update($this->admin(1), $this->admin(2), ['roles' => ['ROLE_USER']]);

        self::assertSame(['begin', 'lock', 'count', 'flush', 'commit'], $this->events);
    }

    public function testADemotionThatWouldLeaveNoAdministratorIsRefusedAndRolledBack(): void
    {
        $target = $this->admin(2);

        try {
            $this->service(remainingAdmins: 0)->update($this->admin(1), $target, ['roles' => ['ROLE_USER']]);
            self::fail('The last administrator was demoted.');
        } catch (UserUpdateRejected $exception) {
            self::assertSame(409, $exception->statusCode());
        }

        self::assertSame(['begin', 'lock', 'count', 'rollback'], $this->events);
        self::assertTrue($target->isAdmin());
    }

    public function testAnUpdateThatRemovesNoAdministratorTakesNoLock(): void
    {
        $this->service(remainingAdmins: 0)->update($this->admin(1), $this->admin(2), ['roles' => ['ROLE_ADMIN', 'ROLE_USER']]);

        self::assertSame(['begin', 'flush', 'commit'], $this->events);
    }

    public function testRevokingResetLinksWaitsForValidationAndCommitsWithThePassword(): void
    {
        $this->service(remainingAdmins: 1)->update($this->admin(1), $this->admin(2), ['password' => 'Valid-password1!']);

        self::assertSame(['begin', 'revoke', 'flush', 'commit'], $this->events);
    }

    public function testAFailedWriteRollsThePasswordAndTheRevocationBackTogether(): void
    {
        $service = $this->service(remainingAdmins: 1, flushFailure: new \RuntimeException('database exploded'));

        $this->expectException(\RuntimeException::class);
        try {
            $service->update($this->admin(1), $this->admin(2), ['password' => 'Valid-password1!']);
        } finally {
            self::assertSame(['begin', 'revoke', 'flush', 'rollback'], $this->events);
        }
    }

    private function admin(int $id): User
    {
        $user = (new User())->setEmail(sprintf('admin%d@test.local', $id))->setRoles(['ROLE_ADMIN', 'ROLE_USER']);
        (new \ReflectionProperty(User::class, 'id'))->setValue($user, $id);

        return $user;
    }

    private function service(int $remainingAdmins, ?\Throwable $flushFailure = null): UserUpdateService
    {
        $connection = $this->createMock(Connection::class);
        $inTransaction = false;
        $connection->method('isTransactionActive')->willReturnCallback(static function () use (&$inTransaction): bool {
            return $inTransaction;
        });

        $users = $this->createMock(UserRepository::class);
        $users->method('lockAdministrators')->willReturnCallback(function (): void {
            $this->events[] = 'lock';
        });
        $users->method('countAdminsExcluding')->willReturnCallback(function () use ($remainingAdmins): int {
            $this->events[] = 'count';

            return $remainingAdmins;
        });

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getConnection')->willReturn($connection);
        $entityManager->method('getRepository')->with(User::class)->willReturn($users);
        $entityManager->method('beginTransaction')->willReturnCallback(function () use (&$inTransaction): void {
            $inTransaction = true;
            $this->events[] = 'begin';
        });
        $entityManager->method('commit')->willReturnCallback(function () use (&$inTransaction): void {
            $inTransaction = false;
            $this->events[] = 'commit';
        });
        $entityManager->method('rollback')->willReturnCallback(function () use (&$inTransaction): void {
            $inTransaction = false;
            $this->events[] = 'rollback';
        });
        $entityManager->method('flush')->willReturnCallback(function () use ($flushFailure): void {
            $this->events[] = 'flush';
            if ($flushFailure !== null) {
                throw $flushFailure;
            }
        });

        $resetTokens = $this->createMock(ResetPasswordTokenRepository::class);
        $resetTokens->method('markOutstandingTokensUsed')->willReturnCallback(function (): void {
            $this->events[] = 'revoke';
        });

        $validator = $this->createMock(ValidatorInterface::class);
        $validator->method('validate')->willReturn(new ConstraintViolationList());

        $hasher = $this->createMock(UserPasswordHasherInterface::class);
        $hasher->method('hashPassword')->willReturn('hashed-password');

        return new UserUpdateService(
            $entityManager,
            $hasher,
            $validator,
            new PasswordValidator(),
            $this->createMock(AdminAuditService::class),
            $this->createMock(SecurityAuditLogger::class),
            $resetTokens,
        );
    }
}
