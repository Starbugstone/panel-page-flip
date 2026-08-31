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
        $cleanup->method('run')->willReturn(['invitations' => 0, 'claimCodes' => 0, 'revokedShares' => 0]);

        $tester = new CommandTester(new CleanupExpiredSharesCommand($cleanup));

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('Nothing to clean up.', $tester->getDisplay());
    }

    public function testReportsWhatWasRemoved(): void
    {
        $cleanup = $this->createMock(ExpiredShareCleanupService::class);
        $cleanup->method('run')->willReturn(['invitations' => 3, 'claimCodes' => 2, 'revokedShares' => 4]);

        $tester = new CommandTester(new CleanupExpiredSharesCommand($cleanup));

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        // The console box wraps long lines, so compare against unwrapped text.
        self::assertStringContainsString(
            'Removed 3 expired invitation(s), 2 dead sharing code(s) and 4 long-revoked share(s).',
            (string) preg_replace('/\s+/', ' ', $tester->getDisplay())
        );
    }

    /**
     * A run that only found revoked shares still reports rather than claiming
     * there was nothing to do — the guard has three counters to check, not two.
     */
    public function testRevokedSharesAloneCountAsWorkDone(): void
    {
        $cleanup = $this->createMock(ExpiredShareCleanupService::class);
        $cleanup->method('run')->willReturn(['invitations' => 0, 'claimCodes' => 0, 'revokedShares' => 1]);

        $tester = new CommandTester(new CleanupExpiredSharesCommand($cleanup));

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringNotContainsString('Nothing to clean up.', $tester->getDisplay());
        self::assertStringContainsString(
            '1 long-revoked share(s)',
            (string) preg_replace('/\s+/', ' ', $tester->getDisplay())
        );
    }
}
