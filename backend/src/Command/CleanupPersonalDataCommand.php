<?php

namespace App\Command;

use App\Service\PersonalDataRetentionService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:cleanup-personal-data',
    description: 'Applies the configured personal-data retention periods',
)]
final class CleanupPersonalDataCommand extends Command
{
    public function __construct(private readonly PersonalDataRetentionService $retention)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $counts = $this->retention->clean();

        $io->definitionList(
            ['Audit logs older than 12 months' => $counts['auditLogs']],
            ['Expired email verification tokens' => $counts['verificationTokens']],
            ['Expired password reset tokens' => $counts['resetTokens']],
            ['Unverified accounts older than 30 days' => $counts['unverifiedAccounts']],
            ['Pending personal-data files deleted' => $counts['filesDeleted']],
            ['Pending personal-data files remaining' => $counts['filesRemaining']],
            ['Quarantined files past retention deleted' => $counts['quarantineFilesDeleted']],
            ['Quarantined files past retention remaining' => $counts['quarantineFilesRemaining']],
            ['Abandoned staged uploads removed' => $counts['stagedUploadsExpired']],
            ['Abandoned staged uploads remaining' => $counts['stagedUploadsRemaining']],
            ['Errors' => $counts['errors']],
        );

        if ($counts['errors'] > 0) {
            $io->warning('Cleanup completed with errors. Check the application log.');
            return Command::FAILURE;
        }

        if ($counts['filesRemaining'] > 0
            || $counts['quarantineFilesRemaining'] > 0
            || $counts['stagedUploadsRemaining'] > 0
        ) {
            $io->warning('Some personal-data files could not be removed and will be retried on the next run. Check that this command\'s user can write to var/quarantine and var/upload-staging.');
        }

        $io->success('Personal-data retention cleanup completed.');
        return Command::SUCCESS;
    }
}
