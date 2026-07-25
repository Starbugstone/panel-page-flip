<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260725130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Preserve admin audit history when an administrator account is deleted.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE admin_audit_log DROP FOREIGN KEY FK_5BCE80B6642B8210');
        $this->addSql('ALTER TABLE admin_audit_log CHANGE admin_user_id admin_user_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE admin_audit_log ADD CONSTRAINT FK_5BCE80B6642B8210 FOREIGN KEY (admin_user_id) REFERENCES `user` (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            (int) $this->connection->fetchOne('SELECT COUNT(*) FROM admin_audit_log WHERE admin_user_id IS NULL') > 0,
            'Cannot restore a mandatory admin reference after an administrator has been deleted.'
        );
        $this->addSql('ALTER TABLE admin_audit_log DROP FOREIGN KEY FK_5BCE80B6642B8210');
        $this->addSql('ALTER TABLE admin_audit_log CHANGE admin_user_id admin_user_id INT NOT NULL');
        $this->addSql('ALTER TABLE admin_audit_log ADD CONSTRAINT FK_5BCE80B6642B8210 FOREIGN KEY (admin_user_id) REFERENCES `user` (id) ON DELETE CASCADE');
    }
}
