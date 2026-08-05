<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260805150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add admin-managed global tags and library-hiding behavior, then seed the defaults.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE tag CHANGE creator_id creator_id INT DEFAULT NULL, ADD is_global TINYINT(1) DEFAULT 0 NOT NULL, ADD hide_from_library TINYINT(1) DEFAULT 0 NOT NULL');

        foreach (['Marvel', 'DC', 'Manga'] as $name) {
            $this->addSql(
                "INSERT INTO tag (creator_id, name, created_at, is_global, hide_from_library) SELECT NULL, ?, NOW(), 1, 0 WHERE NOT EXISTS (SELECT 1 FROM tag WHERE is_global = 1 AND LOWER(name) = LOWER(?))",
                [$name, $name]
            );
        }
        $this->addSql(
            "INSERT INTO tag (creator_id, name, created_at, is_global, hide_from_library) SELECT NULL, 'Hidden', NOW(), 1, 1 WHERE NOT EXISTS (SELECT 1 FROM tag WHERE is_global = 1 AND LOWER(name) = 'hidden')"
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM tag WHERE is_global = 1 AND name IN ('Marvel', 'DC', 'Manga', 'Hidden') AND NOT EXISTS (SELECT 1 FROM comic_tag WHERE comic_tag.tag_id = tag.id)");
        $this->addSql('ALTER TABLE tag CHANGE creator_id creator_id INT NOT NULL, DROP is_global, DROP hide_from_library');
    }
}
