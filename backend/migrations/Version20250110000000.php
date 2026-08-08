<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Historical migration, restored so Doctrine stops reporting history drift.
 *
 * The class went missing from the repository while the version stayed recorded
 * in deployed databases, so every `doctrine:migrations:status` reported an
 * executed-but-unavailable version and hid genuine pending work behind the
 * noise. Nothing in the application reads `deployment_history`; it belongs to
 * the older git-based rollback system.
 *
 * Restoring it creates a third case, though. An installation may have the table
 * without the version row — the table was created, then the class was lost
 * before that database recorded it. A plain CREATE TABLE aborts the whole deploy
 * there on "table already exists", for a table nothing uses. Hence the
 * conditional forms below: on a fresh database this creates the table, on that
 * third case it is a no-op, and either way the version is recorded and the
 * history finally agrees with itself.
 *
 * The obvious `$this->skipIf($schema->hasTable(...))` guard is wrong twice over.
 * `schema_filter` in doctrine.yaml deliberately excludes this table so
 * `doctrine:schema:validate` does not flag a table with no entity, so any
 * introspection — the injected Schema or the schema manager — answers "no such
 * table" regardless of reality. And skipIf leaves the migration unrecorded, so
 * the drift this class exists to fix would simply come back on the next run.
 */
final class Version20250110000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create deployment_history table for git-based rollback system';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE IF NOT EXISTS deployment_history (
            id INT AUTO_INCREMENT NOT NULL,
            commit_hash VARCHAR(40) NOT NULL,
            branch VARCHAR(255) NOT NULL,
            repository VARCHAR(255) DEFAULT NULL,
            github_run_id VARCHAR(255) DEFAULT NULL,
            deployed_at DATETIME NOT NULL,
            status VARCHAR(20) NOT NULL,
            deployment_steps LONGTEXT DEFAULT NULL,
            duration NUMERIC(8, 2) DEFAULT NULL,
            deployed_by VARCHAR(255) DEFAULT NULL,
            rollback_reason LONGTEXT DEFAULT NULL,
            rolled_back_at DATETIME DEFAULT NULL,
            rolled_back_to_commit VARCHAR(40) DEFAULT NULL,
            PRIMARY KEY(id),
            INDEX idx_commit_hash (commit_hash),
            INDEX idx_status (status),
            INDEX idx_deployed_at (deployed_at)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS deployment_history');
    }
}
