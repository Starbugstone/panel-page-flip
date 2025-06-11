<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20250110000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create deployment_history table for git-based rollback system';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE deployment_history (
            id INT AUTO_INCREMENT NOT NULL, 
            commit_hash VARCHAR(40) NOT NULL, 
            branch VARCHAR(255) NOT NULL, 
            repository VARCHAR(255) DEFAULT NULL, 
            github_run_id VARCHAR(255) DEFAULT NULL, 
            deployed_at DATETIME NOT NULL, 
            status VARCHAR(20) NOT NULL, 
            deployment_steps LONGTEXT DEFAULT NULL, 
            duration NUMERIC(8, 2) DEFAULT NULL, 
            deployed_by VARCHAR(255) DEFAULT NULL, 
            rollback_reason LONGTEXT DEFAULT NULL, 
            rolled_back_at DATETIME DEFAULT NULL, 
            rolled_back_to_commit VARCHAR(40) DEFAULT NULL, 
            PRIMARY KEY(id),
            INDEX idx_commit_hash (commit_hash),
            INDEX idx_status (status),
            INDEX idx_deployed_at (deployed_at)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE deployment_history');
    }
} 