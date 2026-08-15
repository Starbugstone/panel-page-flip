<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The provider credential and access model for the close-out of issue #74.
 *
 * Metron moves from an account username and password to a revocable bearer
 * token, so the old columns are dropped rather than carried: they held an
 * account password, which the new flow never asks for and must not keep. Any
 * installation that had Metron configured re-enters a token, which is the
 * intended outcome of the migration rather than a casualty of it.
 */
final class Version20260815120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Metron bearer tokens, per-user provider credentials, provider kill switches and metadata provenance.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\AbstractMySQLPlatform,
            'This migration targets MySQL.'
        );

        $this->addSql(<<<'SQL'
            ALTER TABLE metadata_provider_configuration
                DROP COLUMN metron_username,
                DROP COLUMN metron_password,
                ADD metron_token VARCHAR(1024) DEFAULT NULL,
                ADD metron_shared_enabled TINYINT(1) DEFAULT 0 NOT NULL,
                ADD comic_vine_enabled TINYINT(1) DEFAULT 1 NOT NULL,
                ADD personal_credentials_enabled TINYINT(1) DEFAULT 1 NOT NULL,
                MODIFY comic_vine_api_key VARCHAR(1024) DEFAULT NULL
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE user_metadata_credential (
                id INT AUTO_INCREMENT NOT NULL,
                user_id INT NOT NULL,
                metron_token VARCHAR(1024) DEFAULT NULL,
                comic_vine_api_key VARCHAR(1024) DEFAULT NULL,
                updated_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                UNIQUE INDEX UNIQ_3251D476A76ED395 (user_id),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE user_metadata_credential
                ADD CONSTRAINT FK_user_metadata_credential_user
                FOREIGN KEY (user_id) REFERENCES user (id) ON DELETE CASCADE
        SQL);

        $this->addSql('ALTER TABLE user ADD metadata_api_enabled TINYINT(1) DEFAULT 1 NOT NULL');

        $this->addSql(<<<'SQL'
            ALTER TABLE comic
                ADD classification JSON DEFAULT NULL,
                ADD metadata_provider VARCHAR(32) DEFAULT NULL,
                ADD metadata_external_id VARCHAR(64) DEFAULT NULL,
                ADD metadata_fetched_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)'
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\AbstractMySQLPlatform,
            'This migration targets MySQL.'
        );

        $this->addSql(<<<'SQL'
            ALTER TABLE comic
                DROP COLUMN classification,
                DROP COLUMN metadata_provider,
                DROP COLUMN metadata_external_id,
                DROP COLUMN metadata_fetched_at
        SQL);

        $this->addSql('ALTER TABLE user DROP COLUMN metadata_api_enabled');
        $this->addSql('DROP TABLE user_metadata_credential');

        $this->addSql(<<<'SQL'
            ALTER TABLE metadata_provider_configuration
                DROP COLUMN metron_token,
                DROP COLUMN metron_shared_enabled,
                DROP COLUMN comic_vine_enabled,
                DROP COLUMN personal_credentials_enabled,
                ADD metron_username VARCHAR(255) DEFAULT NULL,
                ADD metron_password VARCHAR(512) DEFAULT NULL,
                MODIFY comic_vine_api_key VARCHAR(512) DEFAULT NULL
        SQL);
    }
}
