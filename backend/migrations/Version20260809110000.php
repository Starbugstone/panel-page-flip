<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The single row that records which comic formats an administrator has turned on.
 *
 * It seeds `["cbz"]` rather than every supported format, because the other
 * formats need `7z`/Poppler on the host and an existing installation has no
 * reason to have them yet. An administrator enables each one after the
 * `app:comic-formats:check` diagnostic says the runtime is there.
 */
final class Version20260809110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Store administrator-enabled comic formats, defaulting to CBZ only.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\AbstractMySQLPlatform,
            'This migration targets MySQL.'
        );

        $this->addSql(
            'CREATE TABLE comic_format_configuration (
                id INT NOT NULL,
                enabled_formats JSON NOT NULL,
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB'
        );

        $this->addSql('INSERT INTO comic_format_configuration (id, enabled_formats) VALUES (1, \'["cbz"]\')');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE comic_format_configuration');
    }
}
