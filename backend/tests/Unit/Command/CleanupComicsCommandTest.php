<?php

declare(strict_types=1);

namespace App\Tests\Unit\Command;

use App\Command\CleanupComicsCommand;
use App\Service\ComicCleanupService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class CleanupComicsCommandTest extends TestCase
{
    public function testDeprecatedDaysOptionIsRefused(): void
    {
        $cleanup = $this->createMock(ComicCleanupService::class);
        $cleanup->expects(self::never())->method('scan');

        $tester = new CommandTester(new CleanupComicsCommand($cleanup));

        self::assertSame(Command::INVALID, $tester->execute(['--days' => '30']));
        self::assertStringContainsString('Age-based comic deletion is disabled', $tester->getDisplay());
    }

    public function testEmptyScanSucceedsWithoutMovingAnything(): void
    {
        $cleanup = $this->createMock(ComicCleanupService::class);
        $cleanup->method('scan')->willReturn([
            'orphanedComics' => [],
            'orphanedCovers' => [],
            'totals' => ['orphanedComics' => 0, 'orphanedCovers' => 0],
        ]);
        $cleanup->expects(self::never())->method('apply');

        $tester = new CommandTester(new CleanupComicsCommand($cleanup));

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('No orphaned files found.', $tester->getDisplay());
    }

    public function testDryRunReportsOrphansWithoutMovingThem(): void
    {
        $cleanup = $this->createMock(ComicCleanupService::class);
        $cleanup->method('scan')->willReturn([
            'orphanedComics' => [['filename' => 'lost.cbz']],
            'orphanedCovers' => [],
            'totals' => ['orphanedComics' => 1, 'orphanedCovers' => 0],
        ]);
        $cleanup->expects(self::never())->method('apply');

        $tester = new CommandTester(new CleanupComicsCommand($cleanup));

        self::assertSame(Command::SUCCESS, $tester->execute(['--dry-run' => true]));
        self::assertStringContainsString('Dry run only', $tester->getDisplay());
    }

    public function testItFailsCleanlyWhenTheDirectoryDisappearsBeforeApply(): void
    {
        $cleanup = $this->createMock(ComicCleanupService::class);
        $cleanup->method('scan')->willReturn([
            'orphanedComics' => [['filename' => 'lost.cbz', 'path' => '/tmp/lost.cbz', 'userId' => null]],
            'orphanedCovers' => [],
            'totals' => ['orphanedComics' => 1, 'orphanedCovers' => 0],
        ]);
        $cleanup->method('apply')->willReturn([
            'orphanedComics' => [],
            'orphanedCovers' => [],
            'totals' => ['orphanedComics' => 0, 'orphanedCovers' => 0],
            'error' => 'Comics directory disappeared.',
        ]);

        $tester = new CommandTester(new CleanupComicsCommand($cleanup));

        self::assertSame(Command::FAILURE, $tester->execute(['--force' => true]));
        self::assertStringContainsString('Comics directory disappeared.', $tester->getDisplay());
    }
}
