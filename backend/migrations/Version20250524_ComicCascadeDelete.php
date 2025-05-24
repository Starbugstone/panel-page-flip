<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Migration to add CASCADE DELETE to comic_reading_progress foreign key constraint
 */
final class Version20250524_ComicCascadeDelete extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add CASCADE DELETE to comic_reading_progress foreign key constraint';
    }

    public function up(Schema $schema): void
    {
        // Drop the existing foreign key constraint
        $this->addSql('ALTER TABLE comic_reading_progress DROP FOREIGN KEY FK_C9B3853FD663094A');
        
        // Re-add it with ON DELETE CASCADE
        $this->addSql('ALTER TABLE comic_reading_progress ADD CONSTRAINT FK_C9B3853FD663094A FOREIGN KEY (comic_id) REFERENCES comic (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // Revert back to the original constraint without CASCADE
        $this->addSql('ALTER TABLE comic_reading_progress DROP FOREIGN KEY FK_C9B3853FD663094A');
        $this->addSql('ALTER TABLE comic_reading_progress ADD CONSTRAINT FK_C9B3853FD663094A FOREIGN KEY (comic_id) REFERENCES comic (id)');
    }
}
