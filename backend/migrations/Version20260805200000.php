<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260805200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Persist personal-data file deletions so failed erasure can be retried safely.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            "CREATE TABLE pending_file_deletion (
                id INT AUTO_INCREMENT NOT NULL,
                path LONGTEXT NOT NULL,
                attempts INT NOT NULL,
                last_error LONGTEXT DEFAULT NULL,
                created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                last_attempt_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB"
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE pending_file_deletion');
    }
}
