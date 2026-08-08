<?php

namespace App\Monolog;

use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\LogRecord;

/**
 * One log file per calendar day, optionally filed under `YYYY/MM/`.
 *
 * Monolog's own {@see \Monolog\Handler\RotatingFileHandler} cannot produce this
 * layout: it validates the date format against a pattern that rejects a nested
 * path, and its cleanup globs a single flat directory. Both assumptions break
 * the moment files live in month folders — which is the point of the layout, so
 * that a year of security logs is not several hundred files in one listing.
 *
 * Deletion is deliberately not done here. A handler that prunes on write does it
 * on whichever request happens to be first past midnight, and a security log is
 * exactly the wrong place to have retention depend on traffic; {@see
 * \App\Service\LogRetentionService} owns it, driven by `app:cleanup-logs`.
 */
class DailyFolderHandler extends StreamHandler
{
    /** The day currently open, so the path is recomputed only when it changes. */
    private ?string $currentDay = null;

    /**
     * @param string $directory    root for this stream's files
     * @param bool   $groupByMonth file under `YYYY/MM/` rather than flat — used
     *                             for the long-retention channels
     */
    public function __construct(
        private readonly string $directory,
        private readonly bool $groupByMonth = false,
        int|string|Level $level = Level::Info,
        bool $bubble = true,
    ) {
        // Group-readable at most: these records name accounts, addresses and
        // the routes somebody was refused.
        parent::__construct($this->pathFor(new \DateTimeImmutable()), $level, $bubble, 0640);
    }

    protected function write(LogRecord $record): void
    {
        // Keyed off the record rather than "now": a buffered handler can flush
        // records written before midnight after it, and those belong in the file
        // for the day they happened.
        $day = $record->datetime->format('Y-m-d');

        if ($day !== $this->currentDay) {
            $this->close();
            $this->currentDay = $day;
            $this->url = $this->preparedPathFor($record->datetime);
        }

        parent::write($record);
    }

    /** The path for this moment, with its month folder in place. */
    private function preparedPathFor(\DateTimeImmutable $moment): string
    {
        $path = $this->pathFor($moment);

        // StreamHandler would create the folders itself, but with a mode as
        // wide as the umask allows. The month folder is new on the first of
        // every month, so this is the normal path and not an edge case.
        $directory = dirname($path);
        if (!is_dir($directory)) {
            @mkdir($directory, 0750, true);
        }

        return $path;
    }

    private function pathFor(\DateTimeImmutable $moment): string
    {
        $directory = rtrim($this->directory, '/\\');
        $prefix = $this->groupByMonth ? $moment->format('Y/m') . '/' : '';

        return sprintf('%s/%s%s.log', $directory, $prefix, $moment->format('Y-m-d'));
    }
}
