<?php

declare(strict_types=1);

namespace App\Tests\Unit\Command;

use App\Command\CommandPasswordUpdater;
use App\Entity\User;
use App\Service\PasswordValidator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class CommandPasswordUpdaterTest extends TestCase
{
    public function testItRejectsInvalidPasswordsWithoutHashing(): void
    {
        $hasher = $this->createMock(UserPasswordHasherInterface::class);
        $hasher->expects(self::never())->method('hashPassword');
        $output = new BufferedOutput();
        $updater = new CommandPasswordUpdater(new PasswordValidator(), $hasher);

        self::assertFalse($updater->update(new User(), 'short', new SymfonyStyle(new ArrayInput([]), $output)));
        self::assertStringContainsString('Password does not meet policy requirements', $output->fetch());
    }

    public function testItStoresTheHashOfAValidPassword(): void
    {
        $user = new User();
        $hasher = $this->createMock(UserPasswordHasherInterface::class);
        $hasher->expects(self::once())
            ->method('hashPassword')
            ->with($user, 'Valid-password1!')
            ->willReturn('hashed-password');
        $updater = new CommandPasswordUpdater(new PasswordValidator(), $hasher);

        self::assertTrue($updater->update(
            $user,
            'Valid-password1!',
            new SymfonyStyle(new ArrayInput([]), new BufferedOutput())
        ));
        self::assertSame('hashed-password', $user->getPassword());
    }
}
