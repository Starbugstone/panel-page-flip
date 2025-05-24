<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20250524144255 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // Check if the share_token table already exists
        $tableExists = $this->connection->executeQuery("SHOW TABLES LIKE 'share_token'")->rowCount() > 0;
        
        if (!$tableExists) {
            // Only create the table if it doesn't exist
            $this->addSql(<<<'SQL'
                CREATE TABLE share_token (id INT AUTO_INCREMENT NOT NULL, comic_id INT NOT NULL, shared_by_user_id INT NOT NULL, token VARCHAR(255) NOT NULL, shared_with_email VARCHAR(255) NOT NULL, created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', is_used TINYINT(1) NOT NULL, expires_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', UNIQUE INDEX UNIQ_D6594DD65F37A13B (token), INDEX IDX_D6594DD6D663094A (comic_id), INDEX IDX_D6594DD6A88FC4FB (shared_by_user_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            SQL);
            $this->addSql(<<<'SQL'
                ALTER TABLE share_token ADD CONSTRAINT FK_D6594DD6D663094A FOREIGN KEY (comic_id) REFERENCES comic (id)
            SQL);
            $this->addSql(<<<'SQL'
                ALTER TABLE share_token ADD CONSTRAINT FK_D6594DD6A88FC4FB FOREIGN KEY (shared_by_user_id) REFERENCES `user` (id)
            SQL);
        }
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql(<<<'SQL'
            ALTER TABLE share_token DROP FOREIGN KEY FK_D6594DD6D663094A
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE share_token DROP FOREIGN KEY FK_D6594DD6A88FC4FB
        SQL);
        $this->addSql(<<<'SQL'
            DROP TABLE share_token
        SQL);
    }
}
