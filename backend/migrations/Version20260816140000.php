<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Record how the notice about a share is getting on, separately from the share.
 *
 * The relationship is what is true; the email is an announcement of it. Rows
 * that predate this column were created under the previous rule — a share only
 * existed if its email had already gone out — so `sent` is the honest value for
 * them, and it is the column default for exactly that reason.
 */
final class Version20260816140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Track share invitation notification delivery separately from the share itself.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\AbstractMySQLPlatform,
            'This migration targets MySQL.'
        );

        $this->addSql("ALTER TABLE comic_share ADD notification_state VARCHAR(16) DEFAULT 'sent' NOT NULL, ADD notified_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)'");
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\AbstractMySQLPlatform,
            'This migration targets MySQL.'
        );

        $this->addSql('ALTER TABLE comic_share DROP notification_state, DROP notified_at');
    }
}
