<?php

declare(strict_types=1);

namespace App\Tests\Unit\Command;

use App\Command\CleanupLogsCommand;
use App\Service\LogRetentionService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class CleanupLogsCommandTest extends TestCase
{
    public function testDryRunDoesNotClaimToHaveDeletedAnything(): void
    {
        $retention = $this->createMock(LogRetentionService::class);
        $retention->expects(self::once())->method('clean')->with(true)->willReturn([
            'app' => $this->stream(),
        ]);

        $tester = new CommandTester(new CleanupLogsCommand($retention));

        self::assertSame(Command::SUCCESS, $tester->execute(['--dry-run' => true]));
        self::assertStringContainsString('Dry run complete. Nothing was deleted.', $tester->getDisplay());
    }

    public function testFailsWhenAStreamReportsErrors(): void
    {
        $retention = $this->createMock(LogRetentionService::class);
        $retention->method('clean')->willReturn([
            'app' => $this->stream(['errors' => 1]),
        ]);

        $tester = new CommandTester(new CleanupLogsCommand($retention));

        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringContainsString('could not be deleted', $tester->getDisplay());
    }

    /** @param array<string, int|string> $overrides */
    private function stream(array $overrides = []): array
    {
        return $overrides + [
            'directory' => '/tmp/logs',
            'retentionDays' => 30,
            'filesDeleted' => 0,
            'directoriesRemoved' => 0,
            'errors' => 0,
        ];
    }
}
