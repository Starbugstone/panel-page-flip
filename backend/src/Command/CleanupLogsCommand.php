<?php

namespace App\Command;

use App\Service\LogRetentionService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:cleanup-logs',
    description: 'Deletes daily log files past their configured retention period',
)]
final class CleanupLogsCommand extends Command
{
    public function __construct(private readonly LogRetentionService $retention)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report what would be deleted without deleting it');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');

        $results = $this->retention->clean($dryRun);

        $io->table(
            ['Stream', 'Directory', 'Retention (days)', $dryRun ? 'Files expired' : 'Files deleted', 'Folders removed', 'Errors'],
            array_map(
                static fn (string $name, array $result): array => [
                    $name,
                    $result['directory'],
                    (string) $result['retentionDays'],
                    (string) $result['filesDeleted'],
                    (string) $result['directoriesRemoved'],
                    (string) $result['errors'],
                ],
                array_keys($results),
                $results
            )
        );

        $errors = array_sum(array_column($results, 'errors'));
        if ($errors > 0) {
            $io->warning('Some expired log files could not be deleted. Check the file permissions on the log directory.');

            return Command::FAILURE;
        }

        $io->success($dryRun ? 'Dry run complete. Nothing was deleted.' : 'Log retention cleanup completed.');

        return Command::SUCCESS;
    }
}
