<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260815200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add durable content reports and reversible legal sharing restrictions.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\AbstractMySQLPlatform,
            'This migration targets MySQL.'
        );

        $this->addSql('ALTER TABLE comic ADD sharing_restricted_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', ADD quarantined_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
        $this->addSql('ALTER TABLE user ADD sharing_restricted_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
        $this->addSql(<<<'SQL'
            CREATE TABLE content_report (
                id INT AUTO_INCREMENT NOT NULL,
                reviewed_by_admin_id INT DEFAULT NULL,
                linked_user_id INT DEFAULT NULL,
                linked_comic_id INT DEFAULT NULL,
                linked_share_id INT DEFAULT NULL,
                reporter_name VARCHAR(200) NOT NULL,
                reporter_organization VARCHAR(200) DEFAULT NULL,
                reporter_role VARCHAR(200) DEFAULT NULL,
                reporter_email VARCHAR(320) NOT NULL,
                category VARCHAR(32) NOT NULL,
                reported_reference LONGTEXT NOT NULL,
                explanation LONGTEXT NOT NULL,
                good_faith_acknowledged_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                status VARCHAR(32) NOT NULL,
                created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                updated_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                reviewed_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                resolution_code VARCHAR(64) DEFAULT NULL,
                resolution_note LONGTEXT DEFAULT NULL,
                legal_hold TINYINT(1) DEFAULT 0 NOT NULL,
                INDEX IDX_AC19072872001902 (reviewed_by_admin_id),
                INDEX IDX_AC190728CC26EB02 (linked_user_id),
                INDEX IDX_AC190728B8634795 (linked_comic_id),
                INDEX IDX_AC19072844E67104 (linked_share_id),
                INDEX IDX_CONTENT_REPORT_STATUS_CREATED (status, created_at),
                INDEX IDX_CONTENT_REPORT_CATEGORY (category),
                PRIMARY KEY(id),
                CONSTRAINT FK_CONTENT_REPORT_REVIEWER FOREIGN KEY (reviewed_by_admin_id) REFERENCES user (id) ON DELETE SET NULL,
                CONSTRAINT FK_CONTENT_REPORT_USER FOREIGN KEY (linked_user_id) REFERENCES user (id) ON DELETE SET NULL,
                CONSTRAINT FK_CONTENT_REPORT_COMIC FOREIGN KEY (linked_comic_id) REFERENCES comic (id) ON DELETE SET NULL,
                CONSTRAINT FK_CONTENT_REPORT_SHARE FOREIGN KEY (linked_share_id) REFERENCES comic_share (id) ON DELETE SET NULL
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE content_report');
        $this->addSql('ALTER TABLE comic DROP sharing_restricted_at, DROP quarantined_at');
        $this->addSql('ALTER TABLE user DROP sharing_restricted_at');
    }
}
