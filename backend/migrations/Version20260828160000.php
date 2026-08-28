<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260828160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add structured content-report locators and durable linked-target snapshots.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\AbstractMySQLPlatform,
            'This migration targets MySQL.'
        );

        $this->addSql(<<<'SQL'
            ALTER TABLE content_report
                ADD reference_type VARCHAR(32) DEFAULT 'other' NOT NULL,
                ADD reported_content_title VARCHAR(255) DEFAULT NULL,
                ADD reported_account_reference VARCHAR(320) DEFAULT NULL,
                ADD source_context LONGTEXT DEFAULT NULL,
                ADD linked_user_id_snapshot INT DEFAULT NULL,
                ADD linked_comic_id_snapshot INT DEFAULT NULL,
                ADD linked_share_id_snapshot INT DEFAULT NULL,
                ADD linked_comic_title_snapshot VARCHAR(255) DEFAULT NULL,
                ADD resolution_method VARCHAR(64) DEFAULT NULL
        SQL);
        $this->addSql(<<<'SQL'
            UPDATE content_report r
            LEFT JOIN comic c ON c.id = r.linked_comic_id
            SET r.linked_user_id_snapshot = r.linked_user_id,
                r.linked_comic_id_snapshot = r.linked_comic_id,
                r.linked_share_id_snapshot = r.linked_share_id,
                r.linked_comic_title_snapshot = c.title
            WHERE r.linked_user_id IS NOT NULL
               OR r.linked_comic_id IS NOT NULL
               OR r.linked_share_id IS NOT NULL
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE content_report
                DROP reference_type,
                DROP reported_content_title,
                DROP reported_account_reference,
                DROP source_context,
                DROP linked_user_id_snapshot,
                DROP linked_comic_id_snapshot,
                DROP linked_share_id_snapshot,
                DROP linked_comic_title_snapshot,
                DROP resolution_method
        SQL);
    }
}
