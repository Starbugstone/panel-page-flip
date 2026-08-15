<?php

declare(strict_types=1);

namespace App\Tests\Unit\Command;

use App\Command\CleanupPersonalDataCommand;
use App\Service\PersonalDataRetentionService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class CleanupPersonalDataCommandTest extends TestCase
{
    public function testSucceedsWhenTheSweepCompletesWithoutErrors(): void
    {
        $retention = $this->createMock(PersonalDataRetentionService::class);
        $retention->method('clean')->willReturn($this->counts());

        $tester = new CommandTester(new CleanupPersonalDataCommand($retention));

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('Personal-data retention cleanup completed.', $tester->getDisplay());
    }

    public function testFailsWhenTheSweepReportsErrors(): void
    {
        $retention = $this->createMock(PersonalDataRetentionService::class);
        $retention->method('clean')->willReturn($this->counts(['errors' => 2]));

        $tester = new CommandTester(new CleanupPersonalDataCommand($retention));

        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringContainsString('Cleanup completed with errors', $tester->getDisplay());
    }

    /** @param array<string, int> $overrides */
    private function counts(array $overrides = []): array
    {
        return $overrides + [
            'auditLogs' => 0,
            'verificationTokens' => 0,
            'resetTokens' => 0,
            'unverifiedAccounts' => 0,
            'filesDeleted' => 0,
            'filesRemaining' => 0,
            'errors' => 0,
        ];
    }
}
