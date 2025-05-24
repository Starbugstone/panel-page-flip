<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add publicCoverPath column to share_token table
 */
final class Version20250524180800 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add publicCoverPath column to share_token table';
    }

    public function up(Schema $schema): void
    {
        // Check if the column already exists
        $columnExists = $this->connection->executeQuery("SHOW COLUMNS FROM `share_token` LIKE 'public_cover_path'")->rowCount() > 0;
        
        if (!$columnExists) {
            $this->addSql('ALTER TABLE share_token ADD public_cover_path VARCHAR(255) DEFAULT NULL');
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE share_token DROP public_cover_path');
    }
}
