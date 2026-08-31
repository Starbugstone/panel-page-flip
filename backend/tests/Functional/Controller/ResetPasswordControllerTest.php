<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Entity\ResetPasswordToken;
use App\Tests\Factory\UserFactory;
use App\Tests\Functional\AbstractApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

final class ResetPasswordControllerTest extends AbstractApiTestCase
{
    public function testForgotPasswordAlwaysSucceedsEvenForUnknownAddresses(): void
    {
        $payload = $this->postJson('/api/forgot-password', ['email' => 'nobody@example.test']);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('If an account exists', $payload['message']);
    }

    public function testForgotPasswordRejectsAnInvalidEmail(): void
    {
        $payload = $this->postJson('/api/forgot-password', ['email' => 'not-an-email']);

        self::assertResponseStatusCodeSame(400);
        self::assertSame('Invalid email format', $payload['message']);
    }

    public function testForgotPasswordSendsAResetForAnExistingAccount(): void
    {
        $user = UserFactory::createOne(['email' => 'reset-me@example.test']);

        $payload = $this->postJson('/api/forgot-password', ['email' => $user->getEmail()]);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('If an account exists', $payload['message']);

        $tokens = self::getContainer()->get(EntityManagerInterface::class)
            ->getRepository(ResetPasswordToken::class)
            ->findBy(['user' => $user]);
        self::assertNotEmpty($tokens);

        self::assertEmailCount(1);
        $message = self::getMailerMessage();
        self::assertNotNull($message);
        self::assertSame('Test Sender', $message->getFrom()[0]->getName());
        self::assertSame('Reset your password - Test Sender', $message->getSubject());
        self::assertStringContainsString('Test Sender', (string) $message->getHtmlBody());
        self::assertStringNotContainsString('Comic Reader', (string) $message->getHtmlBody());
    }

    public function testValidateTokenRejectsAGuess(): void
    {
        $payload = $this->getJson('/api/reset-password/validate/' . str_repeat('ab', 32));

        self::assertResponseStatusCodeSame(400);
        self::assertSame('Invalid or expired token', $payload['message']);
    }

    public function testResetRejectsAnEmptyPassword(): void
    {
        $payload = $this->postJson('/api/reset-password/reset/' . str_repeat('ab', 32), ['password' => '']);

        self::assertResponseStatusCodeSame(400);
        self::assertSame('Password cannot be empty', $payload['message']);
    }

    public function testResetRejectsAPasswordThatFailsPolicy(): void
    {
        $payload = $this->postJson('/api/reset-password/reset/' . str_repeat('ab', 32), [
            'password' => 'short',
        ]);

        self::assertResponseStatusCodeSame(400);
        self::assertSame('Password does not meet policy requirements.', $payload['message']);
        self::assertNotEmpty($payload['errors']['password']);
    }

    public function testAValidTokenCanResetThePassword(): void
    {
        $user = UserFactory::createOne(['password' => 'Old!Password123']);
        $plainToken = bin2hex(random_bytes(32));
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);

        $token = (new ResetPasswordToken())
            ->setUser($user)
            ->setToken(hash('sha256', $plainToken))
            ->setExpiresAt(new \DateTimeImmutable('+1 hour'))
            ->setIsUsed(false);
        $entityManager->persist($token);
        $entityManager->flush();

        $this->getJson('/api/reset-password/validate/' . $plainToken);
        self::assertResponseIsSuccessful();

        $payload = $this->postJson('/api/reset-password/reset/' . $plainToken, [
            'password' => 'New!Password123',
        ]);

        self::assertResponseIsSuccessful();
        self::assertSame('Password has been reset successfully', $payload['message']);

        self::assertEmailCount(1);
        $message = self::getMailerMessage();
        self::assertNotNull($message);
        self::assertSame('Test Sender', $message->getFrom()[0]->getName());
        self::assertSame('Your Password Has Been Changed - Test Sender', $message->getSubject());
        self::assertStringContainsString('Test Sender', (string) $message->getHtmlBody());
        self::assertStringContainsString('reset your password immediately', (string) $message->getHtmlBody());
        self::assertStringNotContainsString('replying to this email', (string) $message->getHtmlBody());
        self::assertStringNotContainsString('Comic Reader', (string) $message->getHtmlBody());

        $this->postJson('/api/login', [
            'email' => $user->getEmail(),
            'password' => 'New!Password123',
        ]);
        self::assertResponseIsSuccessful();
    }
}
