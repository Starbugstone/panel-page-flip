<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260507213000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add admin audit log and Dropbox sync tracking.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE `user` ADD dropbox_last_synced_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
        $this->addSql('CREATE TABLE admin_audit_log (id INT AUTO_INCREMENT NOT NULL, admin_user_id INT NOT NULL, action VARCHAR(100) NOT NULL, target_type VARCHAR(100) NOT NULL, target_id INT DEFAULT NULL, payload JSON DEFAULT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX IDX_5BCE80B6642B8210 (admin_user_id), INDEX IDX_ADMIN_AUDIT_CREATED_AT (created_at), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE admin_audit_log ADD CONSTRAINT FK_5BCE80B6642B8210 FOREIGN KEY (admin_user_id) REFERENCES `user` (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE admin_audit_log DROP FOREIGN KEY FK_5BCE80B6642B8210');
        $this->addSql('DROP TABLE admin_audit_log');
        $this->addSql('ALTER TABLE `user` DROP dropbox_last_synced_at');
    }
}
