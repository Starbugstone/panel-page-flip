<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Record when a Dropbox access token stops working.
 *
 * Without it there was no way to tell a live token from a dead one, so every
 * call that needed a client refreshed first — a blocking round trip to Dropbox
 * in front of every status check, listing, import and sync, for a token good
 * for hours.
 */
final class Version20260807220000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add user.dropbox_token_expires_at so Dropbox tokens are only refreshed when they lapse.';
    }

    public function up(Schema $schema): void
    {
        // Nullable, and null for every account connected before this existed.
        // The factory reads an unknown expiry as expired, so those accounts
        // refresh once — recording an expiry — and then stop.
        $this->addSql(
            'ALTER TABLE `user`
                ADD dropbox_token_expires_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\''
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE `user` DROP dropbox_token_expires_at');
    }
}
