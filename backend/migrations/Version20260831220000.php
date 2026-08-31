<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260831220000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Remove persisted values that no application behavior reads.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\AbstractMySQLPlatform,
            'This migration targets MySQL.'
        );

        $this->addSql('ALTER TABLE pending_file_deletion DROP last_error, DROP last_attempt_at');
        $this->addSql('ALTER TABLE share_claim_code_redemption DROP redeemed_at');
        $this->addSql('ALTER TABLE user_warning DROP FOREIGN KEY FK_USER_WARNING_SHARE');
        $this->addSql('DROP INDEX IDX_184382CC2AE63FDB ON user_warning');
        $this->addSql('ALTER TABLE user_warning DROP share_id');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\AbstractMySQLPlatform,
            'This migration targets MySQL.'
        );

        $this->addSql('ALTER TABLE pending_file_deletion ADD last_error LONGTEXT DEFAULT NULL, ADD last_attempt_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
        $this->addSql('ALTER TABLE share_claim_code_redemption ADD redeemed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT \'(DC2Type:datetime_immutable)\'');
        $this->addSql('ALTER TABLE user_warning ADD share_id INT DEFAULT NULL');
        $this->addSql('CREATE INDEX IDX_184382CC2AE63FDB ON user_warning (share_id)');
        $this->addSql('ALTER TABLE user_warning ADD CONSTRAINT FK_USER_WARNING_SHARE FOREIGN KEY (share_id) REFERENCES comic_share (id) ON DELETE SET NULL');
    }
}
