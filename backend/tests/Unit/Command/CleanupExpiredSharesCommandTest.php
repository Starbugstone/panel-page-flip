<?php

declare(strict_types=1);

namespace App\Tests\Unit\Command;

use App\Command\CleanupExpiredSharesCommand;
use App\Service\ExpiredShareCleanupService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class CleanupExpiredSharesCommandTest extends TestCase
{
    public function testReportsWhenNothingWasRemoved(): void
    {
        $cleanup = $this->createMock(ExpiredShareCleanupService::class);
        $cleanup->method('run')->willReturn(['invitations' => 0, 'claimCodes' => 0]);

        $tester = new CommandTester(new CleanupExpiredSharesCommand($cleanup));

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('Nothing to clean up.', $tester->getDisplay());
    }

    public function testReportsWhatWasRemoved(): void
    {
        $cleanup = $this->createMock(ExpiredShareCleanupService::class);
        $cleanup->method('run')->willReturn(['invitations' => 3, 'claimCodes' => 2]);

        $tester = new CommandTester(new CleanupExpiredSharesCommand($cleanup));

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('Removed 3 expired invitation(s) and 2 dead sharing code(s).', $tester->getDisplay());
    }
}
