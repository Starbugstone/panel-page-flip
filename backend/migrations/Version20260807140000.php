<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Drain pending deletions that point into the retired public-shares directory.
 *
 * `public_shares_directory` is no longer one of PendingFileDeletionService's
 * allowed roots, and its retry loop re-validates the path on every pass. A row
 * queued while the old sharing flow was live would therefore fail
 * assertAllowedPath() forever: attempts climbing, an error logged each run, and
 * the record never draining. The files themselves were removed with the feature.
 */
final class Version20260807140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Remove pending file deletions for the retired public-shares directory.';
    }

    public function up(Schema $schema): void
    {
        // Matched on the path segment rather than an absolute prefix: the root
        // was derived from kernel.project_dir, so it differs between the
        // installations this has to run on. Anchoring on the directory name a
        // public share always sat in keeps it from reaching anything else.
        $this->addSql(
            "DELETE FROM pending_file_deletion
             WHERE path LIKE '%/public/shared/%'
                OR path LIKE '%\\\\public\\\\shared\\\\%'
                OR path LIKE '%/public_shares/%'
                OR path LIKE '%\\\\public_shares\\\\%'"
        );
    }

    public function down(Schema $schema): void
    {
        // The rows described files that no longer exist and could never be
        // processed; there is nothing to restore them from.
        $this->throwIrreversibleMigrationException(
            'Pending deletions for the retired public-shares directory cannot be restored.'
        );
    }
}
