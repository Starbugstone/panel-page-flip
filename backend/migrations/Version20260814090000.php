<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Structured metadata read out of a comic's own ComicInfo.xml.
 *
 * The scalars that later navigation and filtering will query get columns;
 * per-page facts go in one JSON document because every consumer reads the whole
 * set at once and none of them filters on it.
 */
final class Version20260814090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Store series, issue, publication and per-page metadata read from comic sources.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\AbstractMySQLPlatform,
            'This migration targets MySQL.'
        );

        $this->addSql(<<<'SQL'
            ALTER TABLE comic
                ADD series VARCHAR(255) DEFAULT NULL,
                ADD issue_number VARCHAR(50) DEFAULT NULL,
                ADD issue_count INT DEFAULT NULL,
                ADD volume INT DEFAULT NULL,
                ADD published_at DATE DEFAULT NULL COMMENT '(DC2Type:date_immutable)',
                ADD language_code VARCHAR(16) DEFAULT NULL,
                ADD age_rating VARCHAR(32) DEFAULT NULL,
                ADD reading_direction VARCHAR(255) DEFAULT 'ltr' NOT NULL,
                ADD creators JSON DEFAULT NULL,
                ADD page_metadata JSON DEFAULT NULL
        SQL);

        $this->addSql('CREATE INDEX IDX_comic_owner_series ON comic (owner_id, series)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IDX_comic_owner_series ON comic');
        $this->addSql(<<<'SQL'
            ALTER TABLE comic
                DROP series,
                DROP issue_number,
                DROP issue_count,
                DROP volume,
                DROP published_at,
                DROP language_code,
                DROP age_rating,
                DROP reading_direction,
                DROP creators,
                DROP page_metadata
        SQL);
    }
}
