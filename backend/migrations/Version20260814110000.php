<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Credentials for the external metadata providers.
 *
 * One row for the installation, seeded empty and with an assigned id, so a
 * fresh install and an upgraded one both find the same row. The secret columns
 * hold ciphertext, which is longer than the keys themselves.
 */
final class Version20260814110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Store credentials for the Metron and Comic Vine metadata providers.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\AbstractMySQLPlatform,
            'This migration targets MySQL.'
        );

        $this->addSql(<<<'SQL'
            CREATE TABLE metadata_provider_configuration (
                id INT NOT NULL,
                metron_username VARCHAR(255) DEFAULT NULL,
                metron_password VARCHAR(512) DEFAULT NULL,
                comic_vine_api_key VARCHAR(512) DEFAULT NULL,
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);

        $this->addSql('INSERT INTO metadata_provider_configuration (id) VALUES (1)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE metadata_provider_configuration');
    }
}
