<?php

declare(strict_types=1);

namespace App\Tests\Unit\Command;

use App\Command\TestMailCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

final class TestMailCommandTest extends TestCase
{
    public function testDefaultMessageUsesTheConfiguredProductName(): void
    {
        $sentEmail = null;
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->method('send')->willReturnCallback(static function (Email $email) use (&$sentEmail): void {
            $sentEmail = $email;
        });

        $tester = new CommandTester(new TestMailCommand(
            $mailer,
            'no-reply@example.test',
            'Test Sender'
        ));

        self::assertSame(Command::SUCCESS, $tester->execute(['--to' => 'reader@example.test']));
        self::assertInstanceOf(Email::class, $sentEmail);
        self::assertSame('Test email from Test Sender', $sentEmail->getSubject());
        self::assertSame('This is a test email from Test Sender.', $sentEmail->getTextBody());
        self::assertStringNotContainsString('Comic Reader', (string) $sentEmail->getHtmlBody());
    }
}
