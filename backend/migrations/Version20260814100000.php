<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Keep the name a comic arrived under.
 *
 * It is evidence about the comic, and discarding it meant a filename could only
 * ever be read once, at import. Storing it lets suggestions be derived on
 * demand, so a better parser improves comics that are already in the library.
 */
final class Version20260814100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Keep the original filename of an imported comic.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\AbstractMySQLPlatform,
            'This migration targets MySQL.'
        );

        $this->addSql('ALTER TABLE comic ADD original_filename VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE comic DROP original_filename');
    }
}
