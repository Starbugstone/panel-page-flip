<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * One reading position per reader per comic, enforced by the database.
 *
 * "Find the row, create it if it is missing" is a read followed by a write, so
 * two saves that arrive together both find nothing and both insert. Later
 * lookups then return whichever duplicate the database offers first and the
 * stored page appears to flip between two values.
 */
final class Version20260807230000 extends AbstractMigration
{
    /**
     * How many times to re-clear duplicates before giving up.
     *
     * Deduplicating and creating the index are two statements, and this project
     * migrates a live installation over HTTP or SSH with no maintenance window —
     * so a reader turning a page in between can insert a fresh duplicate and
     * fail the index creation. Each attempt closes a smaller window than the
     * last; three is far more than a single stray save needs, and failing after
     * that is correct rather than looping against a genuine problem.
     */
    private const ATTEMPTS = 3;

    public function getDescription(): string
    {
        return 'Collapse duplicate reading-progress rows and make (user, comic) unique.';
    }

    /**
     * DDL in MySQL commits implicitly, so wrapping this in a transaction would
     * only promise an atomicity it cannot deliver — and would stop the retry
     * below from seeing its own cleanup.
     */
    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        for ($attempt = 1; $attempt <= self::ATTEMPTS; $attempt++) {
            $this->removeDuplicateProgressRows();

            try {
                $this->connection->executeStatement(
                    'CREATE UNIQUE INDEX UNIQ_reading_progress_user_comic
                        ON comic_reading_progress (user_id, comic_id)'
                );

                return;
            } catch (UniqueConstraintViolationException $exception) {
                // A save landed between the cleanup and this statement. Clear
                // again and retry; on the last attempt let it fail loudly, since
                // re-running the migration is safe and a persistent failure
                // means something other than a race.
                if ($attempt === self::ATTEMPTS) {
                    throw $exception;
                }

                $this->write(sprintf(
                    'A concurrent reading-progress save recreated a duplicate; retrying (%d/%d).',
                    $attempt,
                    self::ATTEMPTS
                ));
            }
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX UNIQ_reading_progress_user_comic ON comic_reading_progress');
    }

    /**
     * The survivor is the row read most recently, falling back to the highest
     * id: that is the one whose page the reader last actually saw.
     */
    private function removeDuplicateProgressRows(): void
    {
        $this->connection->executeStatement(
            'DELETE stale FROM comic_reading_progress stale
             JOIN comic_reading_progress keeper
               ON keeper.user_id = stale.user_id
              AND keeper.comic_id = stale.comic_id
              AND (
                    keeper.last_read_at > stale.last_read_at
                 OR (keeper.last_read_at = stale.last_read_at AND keeper.id > stale.id)
              )'
        );
    }
}
