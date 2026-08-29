<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Sharing codes: the rotatable address somebody shares with an account by, and
 * the disposable code an owner hands out to give comics away.
 *
 * `user.sharing_code` is nullable and left empty here. Filling it for every
 * existing row would mean generating a unique value per user inside a migration
 * that has to run against a live installation over HTTP; the application issues
 * one the first time an account asks for it instead, which is a single row and
 * a single unique-index check. The column is still `UNIQUE`, so two accounts
 * cannot end up on the same address however the value arrives.
 *
 * `comic_share.recipient_alias_name` and `recipient_sharing_code` are what the
 * owner is shown in place of an address they were never given. Null for every
 * existing row, because every existing share was made by typing an address.
 */
final class Version20260809100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add receiver sharing codes, claim codes, and code-recipient aliases on shares.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\AbstractMySQLPlatform,
            'This migration targets MySQL.'
        );

        // Index names are the ones Doctrine derives from the table and column,
        // because `doctrine:schema:validate` runs in CI and compares them.
        $this->addSql('ALTER TABLE `user` ADD sharing_code VARCHAR(16) DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_8D93D649CFA8625F ON `user` (sharing_code)');

        $this->addSql(
            'ALTER TABLE comic_share
                ADD recipient_alias_name VARCHAR(255) DEFAULT NULL,
                ADD recipient_sharing_code VARCHAR(16) DEFAULT NULL'
        );

        $this->addSql(
            'CREATE TABLE share_claim_code (
                id INT AUTO_INCREMENT NOT NULL,
                owner_id INT NOT NULL,
                code_hash VARCHAR(64) NOT NULL,
                max_uses INT NOT NULL,
                uses_remaining INT NOT NULL,
                created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
                expires_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
                revoked_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\',
                sender_responsibility_accepted_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
                UNIQUE INDEX UNIQ_DDFF9F7AE7530879 (code_hash),
                INDEX IDX_DDFF9F7A7E3C61F9 (owner_id),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB'
        );

        $this->addSql(
            'ALTER TABLE share_claim_code
                ADD CONSTRAINT FK_SHARE_CLAIM_CODE_OWNER
                FOREIGN KEY (owner_id) REFERENCES `user` (id) ON DELETE CASCADE'
        );

        // The join rows cascade on both sides. A deleted comic leaves the code
        // still offering whatever else was on it, and a deleted code takes only
        // its own rows with it — never a comic.
        $this->addSql(
            'CREATE TABLE share_claim_code_comic (
                share_claim_code_id INT NOT NULL,
                comic_id INT NOT NULL,
                INDEX IDX_73FD77C2E4FC5133 (share_claim_code_id),
                INDEX IDX_73FD77C2D663094A (comic_id),
                PRIMARY KEY(share_claim_code_id, comic_id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB'
        );

        $this->addSql(
            'ALTER TABLE share_claim_code_comic
                ADD CONSTRAINT FK_SHARE_CLAIM_CODE_COMIC_CODE
                FOREIGN KEY (share_claim_code_id) REFERENCES share_claim_code (id) ON DELETE CASCADE'
        );
        $this->addSql(
            'ALTER TABLE share_claim_code_comic
                ADD CONSTRAINT FK_SHARE_CLAIM_CODE_COMIC_COMIC
                FOREIGN KEY (comic_id) REFERENCES comic (id) ON DELETE CASCADE'
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE share_claim_code_comic DROP FOREIGN KEY FK_SHARE_CLAIM_CODE_COMIC_CODE');
        $this->addSql('ALTER TABLE share_claim_code_comic DROP FOREIGN KEY FK_SHARE_CLAIM_CODE_COMIC_COMIC');
        $this->addSql('DROP TABLE share_claim_code_comic');
        $this->addSql('ALTER TABLE share_claim_code DROP FOREIGN KEY FK_SHARE_CLAIM_CODE_OWNER');
        $this->addSql('DROP TABLE share_claim_code');
        $this->addSql('ALTER TABLE comic_share DROP recipient_alias_name, DROP recipient_sharing_code');
        $this->addSql('DROP INDEX UNIQ_8D93D649CFA8625F ON `user`');
        $this->addSql('ALTER TABLE `user` DROP sharing_code');
    }
}
