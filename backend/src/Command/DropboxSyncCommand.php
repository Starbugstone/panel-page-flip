<?php

namespace App\Command;

use App\Entity\User;
use App\Service\DropboxClientFactory;
use App\Service\DropboxImportService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Syncs comics from Dropbox for all connected users.
 *
 * This command can be run manually or scheduled via cron to automatically
 * sync new enabled comic sources from users' Dropbox accounts.
 *
 * Usage Examples:
 * --------------
 *
 * 1. Sync all users (default limit from DROPBOX_SYNC_LIMIT):
 *    php bin/console app:dropbox-sync
 *
 * 2. Sync all users with custom limit:
 *    php bin/console app:dropbox-sync --limit=5
 *
 * 3. Sync specific user by ID:
 *    php bin/console app:dropbox-sync --user-id=123
 *
 * 4. Dry run (show what would be synced without actually syncing):
 *    php bin/console app:dropbox-sync --dry-run
 *
 * 5. Cron job example (run at midnight with 3 file limit):
 *    0 0 * * * cd /path/to/project && php bin/console app:dropbox-sync --limit=3
 *
 * The listing, duplicate detection and import logic live in DropboxImportService,
 * which this command shares with the /api/dropbox endpoints.
 */
#[AsCommand(
    name: 'app:dropbox-sync',
    description: 'Syncs comics from Dropbox for all connected users.',
)]
class DropboxSyncCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly DropboxClientFactory $dropboxClientFactory,
        private readonly DropboxImportService $dropboxImport,
        private readonly int $defaultSyncLimit
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('user-id', null, InputOption::VALUE_OPTIONAL, 'Sync only for specific user ID')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would be synced without actually syncing')
            ->addOption('limit', null, InputOption::VALUE_OPTIONAL, 'Maximum number of files to sync per user', null);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');
        $limit = $input->getOption('limit') ? (int) $input->getOption('limit') : $this->defaultSyncLimit;

        if ($dryRun) {
            $io->note('Running in dry-run mode - no files will be downloaded or comics created');
        }

        $io->info(sprintf('Sync limit: %d files per user', $limit));

        $users = $this->resolveUsers($input->getOption('user-id'), $io);
        if ($users === null) {
            return Command::FAILURE;
        }

        if ($users === []) {
            $io->info('No users with Dropbox connections found');
            return Command::SUCCESS;
        }

        $io->info(sprintf('Found %d user(s) with Dropbox connections', count($users)));

        $totalNewFiles = 0;
        $totalErrors = 0;

        foreach ($users as $user) {
            $io->section(sprintf('Processing user: %s (ID: %d)', $user->getEmail(), $user->getId()));

            try {
                $result = $this->syncUserDropbox($user, $io, $dryRun, $limit);
                if (!$dryRun) {
                    $user->setDropboxLastSyncedAt(new \DateTimeImmutable());
                    $this->entityManager->flush();
                }
                $totalNewFiles += $result['newFiles'];
                $totalErrors += $result['errors'];
            } catch (\Throwable $e) {
                $io->error(sprintf('Failed to sync user %s: %s', $user->getEmail(), $e->getMessage()));
                $totalErrors++;
            }
        }

        $io->success(sprintf(
            'Sync completed! %d new files synced, %d errors encountered',
            $totalNewFiles,
            $totalErrors
        ));

        return $totalErrors > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * @return list<User>|null Null signals a fatal argument error already reported to the console.
     */
    private function resolveUsers(mixed $userId, SymfonyStyle $io): ?array
    {
        $userRepository = $this->entityManager->getRepository(User::class);

        if (!$userId) {
            return $userRepository->createQueryBuilder('u')
                ->where('u.dropboxAccessToken IS NOT NULL')
                ->andWhere('u.dropboxAccessToken != :empty')
                ->setParameter('empty', '')
                ->getQuery()
                ->getResult();
        }

        $user = $userRepository->find($userId);
        if (!$user) {
            $io->error("User with ID {$userId} not found");
            return null;
        }

        if (!$user->getDropboxAccessToken()) {
            $io->error("User with ID {$userId} does not have Dropbox connected");
            return null;
        }

        return [$user];
    }

    /**
     * @return array{newFiles: int, errors: int}
     */
    private function syncUserDropbox(User $user, SymfonyStyle $io, bool $dryRun, int $limit): array
    {
        try {
            $client = $this->dropboxClientFactory->createForUser($user);

            // The loop itself lives in the service, shared with the HTTP sync
            // endpoint; this callback is all the console adds to it.
            $result = $this->dropboxImport->syncUser(
                $client,
                $user,
                $limit,
                $dryRun,
                fn (string $event, array $context) => $this->report($io, $dryRun, $event, $context)
            );

            return ['newFiles' => $result['newFiles'], 'errors' => $result['failed']];
        } catch (\Throwable $e) {
            $io->error('Failed to connect to Dropbox: ' . $e->getMessage());

            return ['newFiles' => 0, 'errors' => 1];
        }
    }

    /**
     * @param array<string, mixed> $context
     */
    private function report(SymfonyStyle $io, bool $dryRun, string $event, array $context): void
    {
        $file = $context['file'] ?? null;
        $tagsInfo = $file && $file['tags'] !== [] ? ' [Tags: ' . implode(', ', $file['tags']) . ']' : '';

        switch ($event) {
            case 'listed':
                $io->text($context['count'] === 0
                    ? 'No enabled comic source files found in Dropbox'
                    : sprintf('Found %d comic source file(s) in Dropbox', $context['count']));
                break;
            case 'skipped':
                $io->text("Skipping {$file['name']} (already imported)");
                break;
            case 'limitReached':
                $io->text(sprintf('Reached limit of %d files for this user. Skipping remaining files.', $context['limit']));
                break;
            case 'importing':
                $folderPath = dirname($file['path']);
                $folderInfo = $folderPath !== '/' ? " (in {$folderPath})" : '';
                $io->text("Processing {$file['name']}{$folderInfo}...");

                if ($dryRun) {
                    $io->text("  [DRY RUN] Would download and import {$file['name']}{$tagsInfo}");
                }
                break;
            case 'imported':
                $io->text("  ✓ Successfully imported {$file['name']}{$tagsInfo}");
                break;
            case 'failed':
                $io->error("  ✗ Failed to import {$file['name']}: " . $context['exception']->getMessage());
                break;
            case 'aborted':
                $io->warning('Stopped this user early after a database failure. The remaining files are untouched and the next run will retry them.');
                break;
        }
    }
}
