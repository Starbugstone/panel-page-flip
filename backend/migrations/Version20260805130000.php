<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260805130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Track a per-reader revision on reading progress so out-of-order saves cannot overwrite a newer page.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE comic_reading_progress ADD revision INT DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE comic_reading_progress DROP revision');
    }
}
