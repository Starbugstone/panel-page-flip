<?php
declare(strict_types=1);
namespace DoctrineMigrations;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
final class Version20260809090000 extends AbstractMigration
{
    public function getDescription(): string { return 'Persist comic source format and default existing comics to CBZ'; }
    public function up(Schema $schema): void { $this->addSql("ALTER TABLE comic ADD source_type VARCHAR(255) DEFAULT 'cbz' NOT NULL"); }
    public function down(Schema $schema): void { $this->addSql('ALTER TABLE comic DROP source_type'); }
}
