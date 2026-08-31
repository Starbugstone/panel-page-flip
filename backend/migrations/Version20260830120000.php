<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260830120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Group the comics from one folder share into one recipient invitation.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\AbstractMySQLPlatform,
            'This migration targets MySQL.'
        );

        $this->addSql('ALTER TABLE comic_share ADD invitation_batch_id VARCHAR(32) DEFAULT NULL, ADD invitation_batch_name VARCHAR(100) DEFAULT NULL, ADD invitation_batch_size INT DEFAULT NULL');
        $this->addSql('CREATE INDEX IDX_comic_share_invitation_batch ON comic_share (invitation_batch_id)');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\AbstractMySQLPlatform,
            'This migration targets MySQL.'
        );

        $this->addSql('DROP INDEX IDX_comic_share_invitation_batch ON comic_share');
        $this->addSql('ALTER TABLE comic_share DROP invitation_batch_id, DROP invitation_batch_name, DROP invitation_batch_size');
    }
}
