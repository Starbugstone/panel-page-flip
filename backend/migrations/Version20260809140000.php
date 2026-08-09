<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Who has claimed a sharing code, so a use means a person rather than a request.
 *
 * `share_claim_code.uses_remaining` on its own cannot say what "ten uses" means:
 * decrementing on every redemption lets one recipient submit the same code ten
 * times and exhaust an offer advertised to ten people. The unique index here is
 * what makes one account cost at most one use, including when two of their
 * requests arrive together.
 *
 * Both foreign keys cascade. A deleted code takes its redemption history with
 * it, and a deleted account stops being counted — neither returns a spent use,
 * because the offer was made and taken.
 */
final class Version20260809140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Record which account redeemed which sharing code, one use per account.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\AbstractMySQLPlatform,
            'This migration targets MySQL.'
        );

        // Index names are the ones Doctrine derives from the table and columns,
        // because `doctrine:schema:validate` runs in CI and compares them.
        $this->addSql(
            'CREATE TABLE share_claim_code_redemption (
                id INT AUTO_INCREMENT NOT NULL,
                claim_code_id INT NOT NULL,
                recipient_id INT NOT NULL,
                redeemed_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
                INDEX IDX_B75072CEB6211129 (claim_code_id),
                INDEX IDX_B75072CEE92F8F78 (recipient_id),
                UNIQUE INDEX uniq_claim_code_recipient (claim_code_id, recipient_id),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB'
        );

        $this->addSql(
            'ALTER TABLE share_claim_code_redemption
                ADD CONSTRAINT FK_B75072CEB6211129
                FOREIGN KEY (claim_code_id) REFERENCES share_claim_code (id) ON DELETE CASCADE'
        );
        $this->addSql(
            'ALTER TABLE share_claim_code_redemption
                ADD CONSTRAINT FK_B75072CEE92F8F78
                FOREIGN KEY (recipient_id) REFERENCES `user` (id) ON DELETE CASCADE'
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE share_claim_code_redemption DROP FOREIGN KEY FK_B75072CEB6211129');
        $this->addSql('ALTER TABLE share_claim_code_redemption DROP FOREIGN KEY FK_B75072CEE92F8F78');
        $this->addSql('DROP TABLE share_claim_code_redemption');
    }
}
