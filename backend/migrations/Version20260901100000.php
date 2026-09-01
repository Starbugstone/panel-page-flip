<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260901100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add provider-neutral social sign-in identities and support passwordless users.';
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

        $this->addSql('ALTER TABLE `user` CHANGE password password VARCHAR(255) DEFAULT NULL');
        $this->addSql('CREATE TABLE user_oauth_identity (id INT AUTO_INCREMENT NOT NULL, user_id INT NOT NULL, provider VARCHAR(32) NOT NULL, provider_subject VARCHAR(255) CHARACTER SET utf8mb4 COLLATE `utf8mb4_bin` NOT NULL, provider_email VARCHAR(180) DEFAULT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', last_used_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX IDX_OAUTH_IDENTITY_USER (user_id), UNIQUE INDEX uniq_oauth_provider_subject (provider, provider_subject), UNIQUE INDEX uniq_oauth_user_provider (user_id, provider), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE user_oauth_identity ADD CONSTRAINT FK_OAUTH_IDENTITY_USER FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\AbstractMySQLPlatform,
            'This migration targets MySQL.'
        );

        $this->addSql('DROP TABLE user_oauth_identity');
        $this->addSql("UPDATE `user` SET password = '!oauth-rollback-requires-password-reset!' WHERE password IS NULL");
        $this->addSql('ALTER TABLE `user` CHANGE password password VARCHAR(255) NOT NULL');
    }
}
