<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Change tag uniqueness from global to per-creator
 */
final class Version20250524203800 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Change tag uniqueness from global to per-creator';
    }

    public function up(Schema $schema): void
    {
        // Drop the existing unique constraint on name
        $this->addSql('ALTER TABLE tag DROP INDEX UNIQ_389B7835E237E06');
        
        // Add a new unique constraint that combines name and creator_id
        $this->addSql('ALTER TABLE tag ADD CONSTRAINT unique_tag_per_creator UNIQUE (name, creator_id)');
    }

    public function down(Schema $schema): void
    {
        // Revert changes: drop the combined unique constraint
        $this->addSql('ALTER TABLE tag DROP INDEX unique_tag_per_creator');
        
        // Add back the original unique constraint on name
        $this->addSql('ALTER TABLE tag ADD CONSTRAINT UNIQ_389B7835E237E06 UNIQUE (name)');
    }
}
