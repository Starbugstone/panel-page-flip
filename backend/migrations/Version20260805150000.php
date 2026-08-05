<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260805150000 extends AbstractMigration
{
    private const RESERVED_GLOBAL_NAMES = ['Marvel', 'DC', 'Manga', 'Hidden'];

    public function getDescription(): string
    {
        return 'Add admin-managed global tags and library-hiding behavior, then seed the defaults.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE tag CHANGE creator_id creator_id INT DEFAULT NULL, ADD is_global TINYINT(1) DEFAULT 0 NOT NULL, ADD hide_from_library TINYINT(1) DEFAULT 0 NOT NULL');

        // Personal tags that already use a reserved default name would otherwise
        // sit alongside the new global of the same name. Rename them first so
        // seeding cannot leave an ambiguous pair in the available-tag set.
        $reservedList = implode(', ', array_map(
            static fn (string $name): string => "'" . addslashes(mb_strtolower($name)) . "'",
            self::RESERVED_GLOBAL_NAMES
        ));
        $this->addSql(
            "UPDATE tag reserved_tag
             LEFT JOIN tag conflicting_tag
               ON conflicting_tag.creator_id = reserved_tag.creator_id
              AND conflicting_tag.id != reserved_tag.id
              AND LOWER(conflicting_tag.name) = LOWER(CONCAT(reserved_tag.name, ' (personal)'))
             SET reserved_tag.name = CASE
                 WHEN conflicting_tag.id IS NULL THEN CONCAT(reserved_tag.name, ' (personal)')
                 ELSE CONCAT(
                     LEFT(reserved_tag.name, 20),
                     ' (personal ',
                     LEFT(SHA2(CONCAT(reserved_tag.id, ':', reserved_tag.creator_id), 256), 16),
                     ')'
                 )
             END
             WHERE reserved_tag.is_global = 0 AND LOWER(reserved_tag.name) IN ($reservedList)"
        );

        foreach (['Marvel', 'DC', 'Manga'] as $name) {
            $this->addSql(
                "INSERT INTO tag (creator_id, name, created_at, is_global, hide_from_library) SELECT NULL, ?, NOW(), 1, 0 WHERE NOT EXISTS (SELECT 1 FROM tag WHERE is_global = 1 AND LOWER(name) = LOWER(?))",
                [$name, $name]
            );
        }
        $this->addSql(
            "INSERT INTO tag (creator_id, name, created_at, is_global, hide_from_library) SELECT NULL, 'Hidden', NOW(), 1, 1 WHERE NOT EXISTS (SELECT 1 FROM tag WHERE is_global = 1 AND LOWER(name) = 'hidden')"
        );

        // MySQL UNIQUE (name, creator_id) treats NULL creator_id as distinct, so
        // duplicate global names would otherwise be allowed. A generated key that
        // is only populated for globals closes that hole at the persistence layer.
        $this->addSql(
            "ALTER TABLE tag ADD global_name_key VARCHAR(50) GENERATED ALWAYS AS (CASE WHEN is_global = 1 THEN LOWER(name) ELSE NULL END) STORED, ADD UNIQUE INDEX unique_global_tag_name (global_name_key)"
        );
    }

    public function down(Schema $schema): void
    {
        // The previous schema cannot represent shared, ownerless tags. Remove
        // their associations and definitions instead of assigning every global
        // tag to an arbitrary user during rollback.
        $this->addSql('DELETE comic_tag FROM comic_tag INNER JOIN tag ON tag.id = comic_tag.tag_id WHERE tag.is_global = 1');
        $this->addSql('DELETE FROM tag WHERE is_global = 1');

        $this->addSql('ALTER TABLE tag DROP INDEX unique_global_tag_name, DROP global_name_key');
        $this->addSql('ALTER TABLE tag CHANGE creator_id creator_id INT NOT NULL, DROP is_global, DROP hide_from_library');
    }
}
