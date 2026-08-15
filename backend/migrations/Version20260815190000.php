<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260815190000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add private virtual library folders and sparse per-user comic placements.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\AbstractMySQLPlatform,
            'This migration targets MySQL.'
        );

        $this->addSql(<<<'SQL'
            CREATE TABLE library_folder (
                id INT AUTO_INCREMENT NOT NULL,
                owner_id INT NOT NULL,
                parent_id INT DEFAULT NULL,
                name VARCHAR(100) NOT NULL,
                parent_scope INT DEFAULT 0 NOT NULL,
                created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                updated_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                INDEX IDX_library_folder_owner_parent (owner_id, parent_id),
                INDEX IDX_47409146727ACA70 (parent_id),
                UNIQUE INDEX UNIQ_library_folder_sibling_name (owner_id, parent_scope, name),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE library_folder_item (
                id INT AUTO_INCREMENT NOT NULL,
                user_id INT NOT NULL,
                comic_id INT NOT NULL,
                folder_id INT NOT NULL,
                created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                UNIQUE INDEX UNIQ_library_folder_item_user_comic (user_id, comic_id),
                INDEX IDX_library_folder_item_user_folder (user_id, folder_id),
                INDEX IDX_library_folder_item_comic (comic_id),
                INDEX IDX_5FD32A2F162CB942 (folder_id),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);
        $this->addSql('ALTER TABLE library_folder ADD CONSTRAINT FK_library_folder_owner FOREIGN KEY (owner_id) REFERENCES `user` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE library_folder ADD CONSTRAINT FK_library_folder_parent FOREIGN KEY (parent_id) REFERENCES library_folder (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE library_folder_item ADD CONSTRAINT FK_library_folder_item_user FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE library_folder_item ADD CONSTRAINT FK_library_folder_item_comic FOREIGN KEY (comic_id) REFERENCES comic (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE library_folder_item ADD CONSTRAINT FK_library_folder_item_folder FOREIGN KEY (folder_id) REFERENCES library_folder (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE library_folder_item');
        $this->addSql('DROP TABLE library_folder');
    }
}
