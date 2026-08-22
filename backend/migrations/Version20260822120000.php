<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Keep an encrypted copy of a sharing code, so its owner can read it back.
 *
 * The hash stays exactly as it was and remains the only thing redemption
 * compares against, so nothing about what a code opens changes here. This
 * column exists for the owner who pasted a code into a conversation and lost
 * it, and whose only recourse was to withdraw a live code and mint another.
 *
 * Nullable with no default: codes issued before this column existed genuinely
 * have nothing to show, and null is the honest representation of that rather
 * than an empty string that would have to be special-cased everywhere.
 */
final class Version20260822120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Store sharing codes encrypted at rest so their owner can view them again.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\AbstractMySQLPlatform,
            'This migration targets MySQL.'
        );

        $this->addSql('ALTER TABLE share_claim_code ADD code_cipher VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\AbstractMySQLPlatform,
            'This migration targets MySQL.'
        );

        $this->addSql('ALTER TABLE share_claim_code DROP code_cipher');
    }
}
