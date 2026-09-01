<?php

declare(strict_types=1);

namespace App\Tests\Unit\Command;

use App\Command\CreateAdminUserCommand;
use App\Command\CreateUserCommand;
use App\Command\CommandPasswordUpdater;
use App\Command\ResetUserPasswordCommand;
use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\PasswordValidator;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class PasswordPolicyCommandTest extends TestCase
{
    public function testCreateAdminUserRejectsAPasswordOutsideTheSharedPolicy(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $passwordHasher = $this->createMock(UserPasswordHasherInterface::class);
        $passwordHasher->method('hashPassword')->willReturn('hashed-password');
        $users = $this->createMock(UserRepository::class);
        $users->method('findOneBy')->willReturn(null);
        $validator = $this->createMock(ValidatorInterface::class);
        $validator->method('validate')->willReturn(new ConstraintViolationList());

        $tester = new CommandTester(new CreateAdminUserCommand(
            $entityManager,
            $passwordHasher,
            $users,
            $validator,
            new PasswordValidator(),
        ));

        $this->assertPasswordIsRejected($tester, 'admin@example.test');
    }

    public function testCreateUserRejectsAPasswordOutsideTheSharedPolicy(): void
    {
        $users = $this->createMock(EntityRepository::class);
        $users->method('findOneBy')->willReturn(null);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getRepository')->with(User::class)->willReturn($users);

        $passwordHasher = $this->createMock(UserPasswordHasherInterface::class);
        $passwordHasher->method('hashPassword')->willReturn('hashed-password');

        $tester = new CommandTester(new CreateUserCommand(
            $entityManager,
            new CommandPasswordUpdater(new PasswordValidator(), $passwordHasher),
        ));

        $this->assertPasswordIsRejected($tester, 'user@example.test');
    }

    public function testResetUserPasswordRejectsAPasswordOutsideTheSharedPolicy(): void
    {
        $users = $this->createMock(EntityRepository::class);
        $users->method('findOneBy')->willReturn((new User())->setEmail('user@example.test'));

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getRepository')->with(User::class)->willReturn($users);

        $passwordHasher = $this->createMock(UserPasswordHasherInterface::class);
        $passwordHasher->method('hashPassword')->willReturn('hashed-password');

        $tester = new CommandTester(new ResetUserPasswordCommand(
            $entityManager,
            new CommandPasswordUpdater(new PasswordValidator(), $passwordHasher),
        ));

        $this->assertPasswordIsRejected($tester, 'user@example.test');
    }

    private function assertPasswordIsRejected(CommandTester $tester, string $email): void
    {
        self::assertSame(Command::FAILURE, $tester->execute([
            'email' => $email,
            'password' => 'short',
        ]));

        $display = $tester->getDisplay();
        foreach ((new PasswordValidator())->validate('short') as $message) {
            self::assertStringContainsString($message, $display);
        }
    }
}
