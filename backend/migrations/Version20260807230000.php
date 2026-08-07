<?php

declare(strict_types=1);

namespace DoctrineMigrations;

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
    public function getDescription(): string
    {
        return 'Collapse duplicate reading-progress rows and make (user, comic) unique.';
    }

    public function up(Schema $schema): void
    {
        // Any duplicates already stored have to go before the index can exist.
        // The survivor is the row read most recently, falling back to the
        // highest id: that is the one whose page the reader last actually saw.
        $this->addSql(
            'DELETE stale FROM comic_reading_progress stale
             JOIN comic_reading_progress keeper
               ON keeper.user_id = stale.user_id
              AND keeper.comic_id = stale.comic_id
              AND (
                    keeper.last_read_at > stale.last_read_at
                 OR (keeper.last_read_at = stale.last_read_at AND keeper.id > stale.id)
              )'
        );

        $this->addSql(
            'CREATE UNIQUE INDEX UNIQ_reading_progress_user_comic
                ON comic_reading_progress (user_id, comic_id)'
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX UNIQ_reading_progress_user_comic ON comic_reading_progress');
    }
}
