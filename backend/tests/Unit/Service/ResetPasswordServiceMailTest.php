<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\ResetPasswordToken;
use App\Entity\User;
use App\Repository\ResetPasswordTokenRepository;
use App\Repository\UserRepository;
use App\Service\PublicUrl;
use App\Service\ResetPasswordService;
use App\Service\SecurityAuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

final class ResetPasswordServiceMailTest extends TestCase
{
    private ?Email $sentEmail = null;

    public function testResetEmailUsesTheConfiguredSiteName(): void
    {
        $user = (new User())
            ->setEmail('reader@example.test')
            ->setName('Reader');

        $service = $this->service($user);

        self::assertTrue($service->sendPasswordResetEmail($user->getEmail()));
        self::assertNotNull($this->sentEmail);
        self::assertSame('Test Sender', $this->sentEmail->getFrom()[0]->getName());
        self::assertSame('Reset your password - Test Sender', $this->sentEmail->getSubject());
        self::assertStringContainsString('Test Sender', (string) $this->sentEmail->getHtmlBody());
        self::assertStringNotContainsString('Comic Reader', (string) $this->sentEmail->getHtmlBody());
    }

    public function testPasswordChangedEmailGivesActionableNoReplySecurityGuidance(): void
    {
        $user = (new User())
            ->setEmail('reader@example.test')
            ->setName('Reader');
        $token = (new ResetPasswordToken())
            ->setUser($user)
            ->setToken(hash('sha256', 'reset-token'))
            ->setExpiresAt(new \DateTimeImmutable('+1 hour'))
            ->setIsUsed(false);

        $service = $this->service($user, $token);

        self::assertTrue($service->resetPassword('reset-token', 'Valid!Password123'));
        self::assertNotNull($this->sentEmail);
        self::assertSame('Test Sender', $this->sentEmail->getFrom()[0]->getName());
        self::assertSame('Your Password Has Been Changed - Test Sender', $this->sentEmail->getSubject());

        $body = (string) $this->sentEmail->getHtmlBody();
        self::assertStringContainsString('Test Sender', $body);
        self::assertStringContainsString('reset your password immediately', $body);
        self::assertStringContainsString('Using a password manager', $body);
        self::assertStringNotContainsString('replying to this email', $body);
        self::assertStringNotContainsString('Changing your password periodically', $body);
        self::assertStringNotContainsString('Comic Reader', $body);
    }

    private function service(User $user, ?ResetPasswordToken $token = null): ResetPasswordService
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);

        $users = $this->createMock(UserRepository::class);
        $users->method('findOneBy')->willReturn($user);

        $tokens = $this->createMock(ResetPasswordTokenRepository::class);
        $tokens->method('findValidToken')->willReturn($token);

        $mailer = $this->createMock(MailerInterface::class);
        $mailer->method('send')->willReturnCallback(function (Email $email): void {
            $this->sentEmail = $email;
        });

        $publicUrl = $this->createMock(PublicUrl::class);
        $publicUrl->method('to')->willReturn('https://example.test/reset-password/reset-token');

        $passwordHasher = $this->createMock(UserPasswordHasherInterface::class);
        $passwordHasher->method('hashPassword')->willReturn('hashed-password');

        return new ResetPasswordService(
            $entityManager,
            $users,
            $tokens,
            $mailer,
            new Environment(new FilesystemLoader(dirname(__DIR__, 3).'/templates')),
            $publicUrl,
            $passwordHasher,
            'no-reply@example.test',
            'Test Sender',
            $this->createMock(LoggerInterface::class),
            $this->createMock(SecurityAuditLogger::class),
        );
    }
}
