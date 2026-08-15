<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260815111500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Consolidate email verification onto the dedicated token table.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            INSERT IGNORE INTO email_verification_token (user_id, token, expires_at, created_at)
            SELECT id, email_verification_token, email_verification_token_expires_at, created_at
            FROM `user`
            WHERE email_verification_token IS NOT NULL
              AND email_verification_token_expires_at IS NOT NULL
        SQL);
        $this->addSql('ALTER TABLE `user` DROP email_verification_token, DROP email_verification_token_expires_at');
    }

    public function down(Schema $schema): void
    {
        $this->addSql("ALTER TABLE `user` ADD email_verification_token VARCHAR(255) DEFAULT NULL, ADD email_verification_token_expires_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)'");
        $this->addSql(<<<'SQL'
            UPDATE `user` u
            LEFT JOIN email_verification_token t ON t.id = (
                SELECT t2.id FROM email_verification_token t2
                WHERE t2.user_id = u.id
                ORDER BY t2.created_at DESC, t2.id DESC
                LIMIT 1
            )
            SET u.email_verification_token = t.token,
                u.email_verification_token_expires_at = t.expires_at
        SQL);
    }
}
