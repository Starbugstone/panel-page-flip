<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260507222000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Hash existing reset and email verification tokens.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("UPDATE reset_password_token SET token = SHA2(token, 256) WHERE token IS NOT NULL AND token NOT REGEXP '^[a-f0-9]{64}$'");
        $this->addSql("UPDATE `user` SET email_verification_token = SHA2(email_verification_token, 256) WHERE email_verification_token IS NOT NULL AND email_verification_token NOT REGEXP '^[a-f0-9]{64}$'");
        $this->addSql("UPDATE email_verification_token SET token = SHA2(token, 256) WHERE token IS NOT NULL AND token NOT REGEXP '^[a-f0-9]{64}$'");
    }

    public function down(Schema $schema): void
    {
        $this->write('Hashed tokens cannot be converted back to plaintext.');
    }
}
