<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260904180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Index the text fields searched by administrative table filters and suggestions.';
    }

    /** MySQL commits these schema changes implicitly. */
    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\AbstractMySQLPlatform,
            'This migration targets MySQL.'
        );

        $this->addSql('CREATE INDEX IDX_user_name ON `user` (name)');
        $this->addSql('CREATE INDEX IDX_comic_title ON comic (title)');
        $this->addSql('CREATE INDEX IDX_comic_author ON comic (author)');
        $this->addSql('CREATE INDEX IDX_admin_audit_action ON admin_audit_log (action)');
        $this->addSql('CREATE INDEX IDX_admin_audit_target_type ON admin_audit_log (target_type)');
        $this->addSql('ALTER TABLE admin_audit_log ADD payload_search LONGTEXT GENERATED ALWAYS AS (LOWER(CAST(payload AS CHAR))) STORED');
        $this->addSql('CREATE FULLTEXT INDEX IDX_admin_audit_payload_search ON admin_audit_log (payload_search)');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\AbstractMySQLPlatform,
            'This migration targets MySQL.'
        );

        $this->addSql('DROP INDEX IDX_user_name ON `user`');
        $this->addSql('DROP INDEX IDX_comic_title ON comic');
        $this->addSql('DROP INDEX IDX_comic_author ON comic');
        $this->addSql('DROP INDEX IDX_admin_audit_action ON admin_audit_log');
        $this->addSql('DROP INDEX IDX_admin_audit_target_type ON admin_audit_log');
        $this->addSql('DROP INDEX IDX_admin_audit_payload_search ON admin_audit_log');
        $this->addSql('ALTER TABLE admin_audit_log DROP payload_search');
    }
}
