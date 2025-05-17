<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20250517184228 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql(<<<'SQL'
            CREATE TABLE comic_tag (comic_id INT NOT NULL, tag_id INT NOT NULL, INDEX IDX_FE821497D663094A (comic_id), INDEX IDX_FE821497BAD26311 (tag_id), PRIMARY KEY(comic_id, tag_id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE comic_reading_progress (id INT AUTO_INCREMENT NOT NULL, user_id INT NOT NULL, comic_id INT NOT NULL, current_page INT NOT NULL, last_read_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', completed TINYINT(1) NOT NULL, INDEX IDX_C9B3853FA76ED395 (user_id), INDEX IDX_C9B3853FD663094A (comic_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE tag (id INT AUTO_INCREMENT NOT NULL, creator_id INT NOT NULL, name VARCHAR(50) NOT NULL, created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', UNIQUE INDEX UNIQ_389B7835E237E06 (name), INDEX IDX_389B78361220EA6 (creator_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE comic_tag ADD CONSTRAINT FK_FE821497D663094A FOREIGN KEY (comic_id) REFERENCES comic (id) ON DELETE CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE comic_tag ADD CONSTRAINT FK_FE821497BAD26311 FOREIGN KEY (tag_id) REFERENCES tag (id) ON DELETE CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE comic_reading_progress ADD CONSTRAINT FK_C9B3853FA76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE comic_reading_progress ADD CONSTRAINT FK_C9B3853FD663094A FOREIGN KEY (comic_id) REFERENCES comic (id)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE tag ADD CONSTRAINT FK_389B78361220EA6 FOREIGN KEY (creator_id) REFERENCES `user` (id)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE comic ADD description LONGTEXT DEFAULT NULL, ADD author VARCHAR(255) DEFAULT NULL, ADD publisher VARCHAR(255) DEFAULT NULL
        SQL);
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql(<<<'SQL'
            ALTER TABLE comic_tag DROP FOREIGN KEY FK_FE821497D663094A
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE comic_tag DROP FOREIGN KEY FK_FE821497BAD26311
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE comic_reading_progress DROP FOREIGN KEY FK_C9B3853FA76ED395
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE comic_reading_progress DROP FOREIGN KEY FK_C9B3853FD663094A
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE tag DROP FOREIGN KEY FK_389B78361220EA6
        SQL);
        $this->addSql(<<<'SQL'
            DROP TABLE comic_tag
        SQL);
        $this->addSql(<<<'SQL'
            DROP TABLE comic_reading_progress
        SQL);
        $this->addSql(<<<'SQL'
            DROP TABLE tag
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE comic DROP description, DROP author, DROP publisher
        SQL);
    }
}
