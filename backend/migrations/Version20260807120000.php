<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Replace copy-on-accept sharing with revocable access to the owner's copy.
 *
 * The old share_token table is dropped rather than migrated. Its rows describe
 * an invitation to *copy* a comic, which the new model has no equivalent for:
 * an unused token was never acted on, and a used one already produced a second
 * Comic row that stays exactly where it is, owned by the recipient. Carrying
 * either forward as a ComicShare would invent an access relationship that never
 * existed.
 */
final class Version20260807120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Introduce ComicShare and ShareInvitationToken; drop the copy-based share_token table.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            "CREATE TABLE comic_share (
                id INT AUTO_INCREMENT NOT NULL,
                comic_id INT DEFAULT NULL,
                owner_id INT DEFAULT NULL,
                recipient_user_id INT DEFAULT NULL,
                recipient_email_normalized VARCHAR(255) NOT NULL,
                status VARCHAR(20) NOT NULL,
                created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                accepted_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                declined_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                revoked_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                recipient_removed_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                expires_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                comic_title_snapshot VARCHAR(255) NOT NULL,
                comic_author_snapshot VARCHAR(255) DEFAULT NULL,
                owner_name_snapshot VARCHAR(255) NOT NULL,
                unavailable_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                tombstone_reason VARCHAR(40) DEFAULT NULL,
                -- The two join-column indexes carry Doctrine's generated names
                -- so the schema stays in sync with the mapping; the ones the
                -- entity names itself keep their readable names.
                INDEX IDX_8E774556D663094A (comic_id),
                INDEX IDX_comic_share_owner (owner_id),
                INDEX IDX_8E774556B15EFB97 (recipient_user_id),
                INDEX IDX_comic_share_recipient_status (recipient_email_normalized, status),
                UNIQUE INDEX UNIQ_comic_share_comic_recipient (comic_id, recipient_email_normalized),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB"
        );

        $this->addSql(
            "CREATE TABLE share_invitation_token (
                id INT AUTO_INCREMENT NOT NULL,
                comic_share_id INT NOT NULL,
                token_hash VARCHAR(64) NOT NULL,
                created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                expires_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                used_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                revoked_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                UNIQUE INDEX UNIQ_2A56F282B3BC57DA (token_hash),
                INDEX IDX_2A56F2824D1FC863 (comic_share_id),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB"
        );

        // SET NULL on the comic and both users is what lets a tombstone outlive
        // the things it describes: the relationship survives to explain the
        // disappearance instead of being cascaded away with it.
        $this->addSql('ALTER TABLE comic_share ADD CONSTRAINT FK_comic_share_comic FOREIGN KEY (comic_id) REFERENCES comic (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE comic_share ADD CONSTRAINT FK_comic_share_owner FOREIGN KEY (owner_id) REFERENCES user (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE comic_share ADD CONSTRAINT FK_comic_share_recipient_user FOREIGN KEY (recipient_user_id) REFERENCES user (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE share_invitation_token ADD CONSTRAINT FK_share_invitation_token_share FOREIGN KEY (comic_share_id) REFERENCES comic_share (id) ON DELETE CASCADE');

        $this->addSql('DROP TABLE IF EXISTS share_token');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS share_invitation_token');
        $this->addSql('DROP TABLE IF EXISTS comic_share');

        $this->addSql(
            "CREATE TABLE share_token (
                id INT AUTO_INCREMENT NOT NULL,
                comic_id INT NOT NULL,
                shared_by_user_id INT NOT NULL,
                token VARCHAR(255) NOT NULL,
                shared_with_email VARCHAR(255) NOT NULL,
                created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                is_used TINYINT(1) NOT NULL,
                expires_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                public_cover_path VARCHAR(255) DEFAULT NULL,
                UNIQUE INDEX UNIQ_share_token_token (token),
                INDEX IDX_share_token_comic (comic_id),
                INDEX IDX_share_token_shared_by (shared_by_user_id),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB"
        );
        $this->addSql('ALTER TABLE share_token ADD CONSTRAINT FK_share_token_comic FOREIGN KEY (comic_id) REFERENCES comic (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE share_token ADD CONSTRAINT FK_share_token_shared_by FOREIGN KEY (shared_by_user_id) REFERENCES user (id)');
    }
}
