<?php

namespace App\Command;

use App\Service\ComicCleanupService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:cleanup-comics',
    description: 'Finds orphaned comic files and moves them to recoverable quarantine storage.',
)]
class CleanupComicsCommand extends Command
{
    public function __construct(private readonly ComicCleanupService $cleanupService)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            // Retained so old scheduled commands fail safely instead of deleting user data.
            ->addOption('days', null, InputOption::VALUE_REQUIRED, 'Deprecated; age-based deletion is disabled')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report orphaned files without moving them')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Skip the confirmation prompt');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if ($input->getOption('days') !== null) {
            $io->error('Age-based comic deletion is disabled because recent access is not a safe deletion policy.');
            return Command::INVALID;
        }

        $scan = $this->cleanupService->scan();
        if (isset($scan['error'])) {
            $io->error($scan['error']);
            return Command::FAILURE;
        }

        $comicCount = $scan['totals']['orphanedComics'];
        $coverCount = $scan['totals']['orphanedCovers'];
        $io->title('Comic file cleanup');
        $io->text(sprintf('Orphaned comic files: %d', $comicCount));
        $io->text(sprintf('Orphaned cover images: %d', $coverCount));

        if ($comicCount === 0 && $coverCount === 0) {
            $io->success('No orphaned files found.');
            return Command::SUCCESS;
        }

        if ($input->getOption('dry-run')) {
            $io->note('Dry run only. No files were moved.');
            return Command::SUCCESS;
        }

        if (!$input->getOption('force') && !$io->confirm('Move these files to recoverable quarantine storage?', false)) {
            $io->warning('Operation cancelled.');
            return Command::SUCCESS;
        }

        $result = $this->cleanupService->apply();
        if (isset($result['error'])) {
            $io->error($result['error']);
            return Command::FAILURE;
        }

        $io->success([
            sprintf('Quarantined %d orphaned comic files.', $result['quarantined']['orphanedComics']),
            sprintf('Quarantined %d orphaned cover images.', $result['quarantined']['orphanedCovers']),
        ]);

        return Command::SUCCESS;
    }
}
