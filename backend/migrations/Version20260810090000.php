<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260810090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Persist the versioned reader preferences that follow each user between devices.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\AbstractMySQLPlatform,
            'This migration targets MySQL.'
        );

        $this->addSql('ALTER TABLE `user` ADD reader_preferences JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE `user` DROP reader_preferences');
    }
}
