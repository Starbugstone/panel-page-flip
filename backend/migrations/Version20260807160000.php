<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Explicit-content classification, and the two acknowledgements a share records.
 *
 * The comic-level flag is what an 18+ warning is derived from — never a tag, and
 * never the library-hiding preference, which says nothing about content. Both
 * acknowledgements live on the share itself so one row is the whole audit trail
 * for one sharing relationship: the sender accepting responsibility, and the
 * recipient declaring their age where the comic requires it.
 */
final class Version20260807160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the explicit-content flag and the sender/recipient share acknowledgements.';
    }

    public function up(Schema $schema): void
    {
        // Existing comics are non-explicit: nobody has been asked yet, and
        // guessing an age rating for somebody else's library is exactly what
        // this feature exists to stop.
        $this->addSql('ALTER TABLE comic ADD explicit_content TINYINT(1) DEFAULT 0 NOT NULL');

        // Nullable for history. Shares created before this existed have no
        // acknowledgement to record and must not pretend otherwise; every share
        // created from now on gets a server-generated timestamp.
        // The snapshot joins the title and author ones already kept here, so a
        // tombstone can still tell that what it describes was 18+ after the
        // comic that would have answered is gone.
        $this->addSql(
            'ALTER TABLE comic_share
                ADD sender_responsibility_accepted_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\',
                ADD adult_confirmed_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\',
                ADD explicit_content_snapshot TINYINT(1) DEFAULT 0 NOT NULL'
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE comic DROP explicit_content');
        $this->addSql(
            'ALTER TABLE comic_share
                DROP sender_responsibility_accepted_at,
                DROP adult_confirmed_at,
                DROP explicit_content_snapshot'
        );
    }
}
