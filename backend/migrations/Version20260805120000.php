<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260805120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Record the source Dropbox path on imported comics so re-syncs can skip them.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE comic ADD dropbox_path VARCHAR(500) DEFAULT NULL');
        $this->addSql('CREATE INDEX IDX_comic_owner_dropbox_path ON comic (owner_id, dropbox_path)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IDX_comic_owner_dropbox_path ON comic');
        $this->addSql('ALTER TABLE comic DROP dropbox_path');
    }
}
