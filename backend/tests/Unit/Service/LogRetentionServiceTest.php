<?php

namespace App\Tests\Unit\Service;

use App\Monolog\DailyFolderHandler;
use App\Service\LogRetentionService;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;

/**
 * Retention as a thing that actually deletes files.
 *
 * A `max_files` setting nothing enforces is the failure mode this exists to
 * avoid: an instance that believes it keeps security logs for a year and
 * ordinary logs for a month, while in fact keeping everything forever. So these
 * tests are about files disappearing, and about the three streams disappearing
 * on different schedules.
 */
final class LogRetentionServiceTest extends TestCase
{
    private string $logsDir;

    protected function setUp(): void
    {
        $this->logsDir = sys_get_temp_dir() . '/ppf-log-retention-' . bin2hex(random_bytes(6));
        mkdir($this->logsDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeRecursively($this->logsDir);
    }

    public function testDeletesExpiredFilesAndKeepsCurrentOnes(): void
    {
        $now = new \DateTimeImmutable('2026-08-08 03:00:00');

        $this->writeLog('app', $now->modify('-40 days'));
        $this->writeLog('app', $now->modify('-31 days'));
        $this->writeLog('app', $now->modify('-29 days'));
        $this->writeLog('app', $now);

        $result = $this->service()->clean(false, $now);

        self::assertSame(2, $result['app']['filesDeleted']);
        self::assertFileDoesNotExist($this->path('app', $now->modify('-40 days')));
        self::assertFileDoesNotExist($this->path('app', $now->modify('-31 days')));
        self::assertFileExists($this->path('app', $now->modify('-29 days')));
        self::assertFileExists($this->path('app', $now));
    }

    /**
     * The whole reason the retention days are separate settings: a security
     * record is asked for months after the application log it sat next to has
     * stopped being interesting.
     */
    public function testSecurityLogsOutliveApplicationLogsByTheConfiguredMargin(): void
    {
        $now = new \DateTimeImmutable('2026-08-08 03:00:00');
        $old = $now->modify('-90 days');

        $this->writeLog('app', $old);
        $this->writeLog('security', $old, true);
        $this->writeLog('audit', $old, true);

        $this->service()->clean(false, $now);

        self::assertFileDoesNotExist($this->path('app', $old));
        self::assertFileExists($this->path('security', $old, true));
        self::assertFileExists($this->path('audit', $old, true));
    }

    public function testRemovesTheMonthAndYearFoldersLeftBehind(): void
    {
        $now = new \DateTimeImmutable('2026-08-08 03:00:00');
        $expired = $now->modify('-400 days');

        $this->writeLog('security', $expired, true);
        $this->writeLog('security', $now, true);

        $result = $this->service()->clean(false, $now);

        self::assertSame(1, $result['security']['filesDeleted']);
        // The month folder and the year above it go with the last file in them;
        // a year of empty directories is its own kind of mess.
        self::assertDirectoryDoesNotExist($this->logsDir . '/security/' . $expired->format('Y/m'));
        self::assertDirectoryDoesNotExist($this->logsDir . '/security/' . $expired->format('Y'));
        self::assertDirectoryExists($this->logsDir . '/security/' . $now->format('Y/m'));
        // The stream root stays: the handler expects to be able to write into it.
        self::assertDirectoryExists($this->logsDir . '/security');
    }

    public function testADryRunReportsWithoutDeleting(): void
    {
        $now = new \DateTimeImmutable('2026-08-08 03:00:00');
        $expired = $now->modify('-60 days');
        $this->writeLog('app', $expired);

        $result = $this->service()->clean(true, $now);

        self::assertSame(1, $result['app']['filesDeleted']);
        self::assertFileExists($this->path('app', $expired));
    }

    /**
     * Age comes from the filename, not the mtime. A restored backup, a copy or
     * a late flush all touch a file without making its contents younger, and
     * retention that trusts the mtime would quietly keep records past the
     * period the instance says it keeps them for.
     */
    public function testAgeIsReadFromTheFilenameNotTheModificationTime(): void
    {
        $now = new \DateTimeImmutable('2026-08-08 03:00:00');
        $expired = $now->modify('-60 days');
        $path = $this->writeLog('app', $expired);
        touch($path, $now->getTimestamp());

        $this->service()->clean(false, $now);

        self::assertFileDoesNotExist($path);
    }

    public function testIgnoresFilesThatAreNotDailyLogs(): void
    {
        $now = new \DateTimeImmutable('2026-08-08 03:00:00');
        mkdir($this->logsDir . '/app', 0777, true);
        file_put_contents($this->logsDir . '/app/README.txt', 'not a log');
        file_put_contents($this->logsDir . '/app/dev.log', 'not a dated log');

        $result = $this->service()->clean(false, $now);

        self::assertSame(0, $result['app']['filesDeleted']);
        self::assertFileExists($this->logsDir . '/app/README.txt');
        self::assertFileExists($this->logsDir . '/app/dev.log');
    }

    public function testMissingDirectoriesAreNotAnError(): void
    {
        $result = $this->service()->clean(false, new \DateTimeImmutable('2026-08-08'));

        self::assertSame(0, $result['security']['filesDeleted']);
        self::assertSame(0, $result['security']['errors']);
    }

    /**
     * The handler and the cleanup have to agree about where files live, so the
     * layout is asserted against the thing that writes it rather than against a
     * path this test made up.
     */
    public function testTheHandlerWritesTheLayoutTheCleanupLooksFor(): void
    {
        $moment = new \DateTimeImmutable('2026-08-08 12:00:00');
        $handler = new DailyFolderHandler($this->logsDir . '/security', true, Level::Info);

        $handler->handle(new LogRecord(
            $moment,
            'security',
            Level::Warning,
            'security.authentication.failed',
            ['actor_user_id' => 1],
        ));
        $handler->close();

        self::assertFileExists($this->logsDir . '/security/2026/08/2026-08-08.log');
    }

    /** A record from before midnight belongs in yesterday's file, not today's. */
    public function testARecordIsFiledUnderItsOwnDayNotTheCurrentOne(): void
    {
        $handler = new DailyFolderHandler($this->logsDir . '/audit', true, Level::Info);

        foreach (['2026-08-07 23:59:59', '2026-08-08 00:00:01'] as $moment) {
            $handler->handle(new LogRecord(
                new \DateTimeImmutable($moment),
                'audit',
                Level::Info,
                'audit.user.password_changed',
                [],
            ));
        }
        $handler->close();

        self::assertFileExists($this->logsDir . '/audit/2026/08/2026-08-07.log');
        self::assertFileExists($this->logsDir . '/audit/2026/08/2026-08-08.log');
    }

    private function service(): LogRetentionService
    {
        return new LogRetentionService($this->logsDir, 30, 365, 365);
    }

    private function writeLog(string $stream, \DateTimeImmutable $day, bool $grouped = false): string
    {
        $path = $this->path($stream, $day, $grouped);
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }
        file_put_contents($path, '{"message":"test"}' . PHP_EOL);

        return $path;
    }

    private function path(string $stream, \DateTimeImmutable $day, bool $grouped = false): string
    {
        return sprintf(
            '%s/%s/%s%s.log',
            $this->logsDir,
            $stream,
            $grouped ? $day->format('Y/m') . '/' : '',
            $day->format('Y-m-d')
        );
    }

    private function removeRecursively(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $entries = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($entries as $entry) {
            /** @var \SplFileInfo $entry */
            $entry->isDir() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
        }

        @rmdir($path);
    }
}
