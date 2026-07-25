<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260725144500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Delete obsolete share tokens automatically when their comic is deleted.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE share_token DROP FOREIGN KEY FK_D6594DD6D663094A');
        $this->addSql('ALTER TABLE share_token ADD CONSTRAINT FK_D6594DD6D663094A FOREIGN KEY (comic_id) REFERENCES comic (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE share_token DROP FOREIGN KEY FK_D6594DD6D663094A');
        $this->addSql('ALTER TABLE share_token ADD CONSTRAINT FK_D6594DD6D663094A FOREIGN KEY (comic_id) REFERENCES comic (id)');
    }
}
