<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260507200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add file size tracking to comics for upload limits and quotas.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE comic ADD file_size BIGINT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE comic DROP file_size');
    }
}
