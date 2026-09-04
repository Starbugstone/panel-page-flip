<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260904214000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Remove the unused full-text index and duplicate audit payload; preserve original JSON records.';
    }

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

        $this->addSql('DROP INDEX IDX_admin_audit_payload_search ON admin_audit_log');
        $this->addSql('ALTER TABLE admin_audit_log DROP payload_search');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\AbstractMySQLPlatform,
            'This migration targets MySQL.'
        );

        $this->addSql('ALTER TABLE admin_audit_log ADD payload_search LONGTEXT GENERATED ALWAYS AS (LOWER(CAST(payload AS CHAR))) STORED');
        $this->addSql('CREATE FULLTEXT INDEX IDX_admin_audit_payload_search ON admin_audit_log (payload_search)');
    }
}
