<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261030210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Replace email addresses in share owner-name snapshots with the public identity.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform,
            'This migration targets MySQL.'
        );

        // The shape as well as the owner's address today: a snapshot can hold one
        // they have since changed. Anything address-shaped that is replaced costs
        // no more than a stale name, and already-clean rows do not match, because
        // a handle has nothing in front of its `@`.
        $emailShaped = "'^[^@[:space:]]+@[^@[:space:]]+$'";

        $this->addSql(<<<SQL
            UPDATE comic_share s
            INNER JOIN `user` u ON u.id = s.owner_id
            SET s.owner_name_snapshot = COALESCE(NULLIF(u.name, ''), CONCAT('@', u.username))
            WHERE s.owner_name_snapshot = u.email
               OR s.owner_name_snapshot REGEXP {$emailShaped}
            SQL);

        // No account left to ask: the wording ComicShare::anonymiseOwner() uses.
        $this->addSql(<<<SQL
            UPDATE comic_share
            SET owner_name_snapshot = 'A former user'
            WHERE owner_id IS NULL
              AND owner_name_snapshot REGEXP {$emailShaped}
            SQL);
    }

    public function down(Schema $schema): void
    {
        // Nothing to restore: the addresses should never have been here.
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform,
            'This migration targets MySQL.'
        );
    }
}
