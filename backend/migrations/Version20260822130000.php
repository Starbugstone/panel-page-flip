<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * A notice an administrator leaves for one account to read on its next visit.
 *
 * Three of the four foreign keys are deliberately not cascades. Losing the
 * administrator who sent a warning, the comic it was about, or the share it
 * concerned must not delete the record that the recipient was told — warning
 * somebody about a comic is usually the step *before* the comic is removed, and
 * a cascade there would erase the notice at exactly the moment it mattered. The
 * recipient's own deletion does cascade: their account going means there is
 * nobody left to read it.
 */
final class Version20260822130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add administrator warnings shown to a user on their next visit.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\AbstractMySQLPlatform,
            'This migration targets MySQL.'
        );

        $this->addSql(<<<'SQL'
            CREATE TABLE user_warning (
                id INT AUTO_INCREMENT NOT NULL,
                recipient_id INT NOT NULL,
                issued_by_id INT DEFAULT NULL,
                comic_id INT DEFAULT NULL,
                share_id INT DEFAULT NULL,
                message LONGTEXT NOT NULL,
                subject VARCHAR(16) NOT NULL,
                subject_label VARCHAR(255) DEFAULT NULL,
                created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                acknowledged_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                email_state VARCHAR(16) DEFAULT 'not_requested' NOT NULL,
                INDEX IDX_184382CCE92F8F78 (recipient_id),
                INDEX IDX_184382CC784BB717 (issued_by_id),
                INDEX IDX_184382CCD663094A (comic_id),
                INDEX IDX_184382CC2AE63FDB (share_id),
                INDEX idx_user_warning_recipient_open (recipient_id, acknowledged_at),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);

        $this->addSql('ALTER TABLE user_warning ADD CONSTRAINT FK_USER_WARNING_RECIPIENT FOREIGN KEY (recipient_id) REFERENCES user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE user_warning ADD CONSTRAINT FK_USER_WARNING_ISSUED_BY FOREIGN KEY (issued_by_id) REFERENCES user (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE user_warning ADD CONSTRAINT FK_USER_WARNING_COMIC FOREIGN KEY (comic_id) REFERENCES comic (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE user_warning ADD CONSTRAINT FK_USER_WARNING_SHARE FOREIGN KEY (share_id) REFERENCES comic_share (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\AbstractMySQLPlatform,
            'This migration targets MySQL.'
        );

        $this->addSql('DROP TABLE user_warning');
    }
}
