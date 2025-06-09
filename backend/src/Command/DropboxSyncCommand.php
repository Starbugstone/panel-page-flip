<?php

namespace App\Command;

use App\Entity\User;
use App\Entity\Comic;
use App\Service\ComicService;
use Doctrine\ORM\EntityManagerInterface;
use Spatie\Dropbox\Client as DropboxClient;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Syncs comics from Dropbox for all connected users.
 *
 * This command can be run manually or scheduled via cron to automatically
 * sync new CBZ files from users' Dropbox accounts.
 *
 * Usage Examples:
 * --------------
 *
 * 1. Sync all users (default: 3 files per user):
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
 * The command will:
 * - Find all users with Dropbox tokens
 * - Check their Dropbox app folder for new CBZ files
 * - Download and import any new comics
 * - Create comics with "Dropbox" tag for easy identification
 * - Store files in user-specific dropbox subdirectories
 */
#[AsCommand(
    name: 'app:dropbox-sync',
    description: 'Syncs comics from Dropbox for all connected users.',
)]
class DropboxSyncCommand extends Command
{
    private EntityManagerInterface $entityManager;
    private ComicService $comicService;
    private string $comicsDirectory;
    private string $dropboxAppFolder;
    private int $defaultSyncLimit;

    public function __construct(
        EntityManagerInterface $entityManager,
        ComicService $comicService,
        string $comicsDirectory,
        string $dropboxAppFolder,
        int $defaultSyncLimit
    ) {
        parent::__construct();
        $this->entityManager = $entityManager;
        $this->comicService = $comicService;
        $this->comicsDirectory = $comicsDirectory;
        $this->dropboxAppFolder = $dropboxAppFolder;
        $this->defaultSyncLimit = $defaultSyncLimit;
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
        $userId = $input->getOption('user-id');
        $dryRun = $input->getOption('dry-run');
        $limit = $input->getOption('limit') ? (int) $input->getOption('limit') : $this->defaultSyncLimit;

        if ($dryRun) {
            $io->note('Running in dry-run mode - no files will be downloaded or comics created');
        }

        $io->info(sprintf('Sync limit: %d files per user', $limit));

        // Get users with Dropbox tokens
        $userRepository = $this->entityManager->getRepository(User::class);
        
        if ($userId) {
            $users = [$userRepository->find($userId)];
            if (!$users[0]) {
                $io->error("User with ID {$userId} not found");
                return Command::FAILURE;
            }
            if (!$users[0]->getDropboxAccessToken()) {
                $io->error("User with ID {$userId} does not have Dropbox connected");
                return Command::FAILURE;
            }
        } else {
            $qb = $userRepository->createQueryBuilder('u')
                ->where('u.dropboxAccessToken IS NOT NULL')
                ->andWhere('u.dropboxAccessToken != :empty')
                ->setParameter('empty', '');
            $users = $qb->getQuery()->getResult();
        }

        if (empty($users)) {
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
                $totalNewFiles += $result['newFiles'];
                $totalErrors += $result['errors'];
            } catch (\Exception $e) {
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

    private function syncUserDropbox(User $user, SymfonyStyle $io, bool $dryRun, int $limit = null): array
    {
        $limit = $limit ?? $this->defaultSyncLimit;
        $newFiles = 0;
        $errors = 0;

        try {
            $client = new DropboxClient($user->getDropboxAccessToken());
            
            // Get existing comics for this user
            $existingComics = $this->entityManager->getRepository(Comic::class)->findBy(['owner' => $user]);
            $existingFiles = array_map(function($comic) {
                return basename($comic->getFilePath());
            }, $existingComics);

            // Get all files recursively with folder structure from the configured app folder
            $allFiles = $this->getAllDropboxFiles($client, $this->dropboxAppFolder);

            if (empty($allFiles)) {
                $io->text('No CBZ files found in Dropbox');
                return ['newFiles' => 0, 'errors' => 0];
            }

            $io->text(sprintf('Found %d CBZ file(s) in Dropbox', count($allFiles)));

            $userDirectory = $this->comicsDirectory . '/' . $user->getId();
            $dropboxDirectory = $userDirectory . '/dropbox';

            if (!$dryRun) {
                // Ensure directories exist
                if (!file_exists($userDirectory)) {
                    mkdir($userDirectory, 0777, true);
                }
                if (!file_exists($dropboxDirectory)) {
                    mkdir($dropboxDirectory, 0777, true);
                }
            }

            $processedCount = 0;
            foreach ($allFiles as $fileInfo) {
                $fileName = basename($fileInfo['path']);
                
                if (in_array($fileName, $existingFiles)) {
                    $io->text("Skipping {$fileName} (already exists)");
                    continue;
                }

                // Check if we've reached the limit for this user
                if ($processedCount >= $limit) {
                    $io->text(sprintf("Reached limit of %d files for this user. Skipping remaining files.", $limit));
                    break;
                }

                $folderPath = dirname($fileInfo['path']);
                $folderInfo = $folderPath !== '/' ? " (in {$folderPath})" : "";
                $io->text("Processing {$fileName}{$folderInfo}...");

                if ($dryRun) {
                    $tagsInfo = !empty($fileInfo['tags']) ? ' [Tags: ' . implode(', ', $fileInfo['tags']) . ']' : '';
                    $io->text("  [DRY RUN] Would download and import {$fileName}{$tagsInfo}");
                    $newFiles++;
                    $processedCount++;
                    continue;
                }

                try {
                    // Download the file from Dropbox
                    $fileContent = $client->download($fileInfo['path']);
                    
                    // Save to dropbox subdirectory
                    $localPath = $dropboxDirectory . '/' . $fileName;
                    file_put_contents($localPath, $fileContent);
                    
                    // Create a temporary UploadedFile object for the ComicService
                    $tempFile = new UploadedFile(
                        $localPath,
                        $fileName,
                        'application/zip',
                        null,
                        true // Test mode
                    );
                    
                    // Extract title from filename
                    $title = pathinfo($fileName, PATHINFO_FILENAME);
                    $title = str_replace(['_', '-'], ' ', $title);
                    $title = ucwords($title);
                    
                    // Create tags from folder structure + Dropbox tag
                    $tags = array_merge(['Dropbox'], $fileInfo['tags']);
                    
                    // Create comic entry
                    $comic = $this->comicService->uploadComic(
                        $tempFile,
                        $user,
                        $title,
                        null, // author
                        null, // publisher
                        'Synced from Dropbox', // description
                        $tags
                    );
                    
                    $tagsInfo = !empty($fileInfo['tags']) ? ' [Tags: ' . implode(', ', $tags) . ']' : '';
                    $io->text("  ✓ Successfully imported {$fileName}{$tagsInfo}");
                    $newFiles++;
                    $processedCount++;
                    
                } catch (\Exception $e) {
                    $io->error("  ✗ Failed to import {$fileName}: " . $e->getMessage());
                    $errors++;
                    $processedCount++; // Count failed attempts towards limit too
                }
            }

        } catch (\Exception $e) {
            $io->error('Failed to connect to Dropbox: ' . $e->getMessage());
            $errors++;
        }

        return ['newFiles' => $newFiles, 'errors' => $errors];
    }

    private function formatFileSize(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        
        $bytes /= (1 << (10 * $pow));
        
        return round($bytes, 2) . ' ' . $units[$pow];
    }

    /**
     * Recursively get all CBZ files from Dropbox with their folder structure
     */
    private function getAllDropboxFiles($client, string $path = '/'): array
    {
        $allFiles = [];
        
        try {
            $response = $client->listFolder($path);
            
            foreach ($response['entries'] as $entry) {
                if ($entry['.tag'] === 'file' && strtolower(pathinfo($entry['name'], PATHINFO_EXTENSION)) === 'cbz') {
                    // Extract folder path and convert to tags
                    $folderPath = trim(dirname($entry['path_display']), '/');
                    $tags = $this->convertPathToTags($folderPath);
                    
                    $allFiles[] = [
                        'path' => $entry['path_display'],
                        'name' => $entry['name'],
                        'size' => $entry['size'],
                        'modified' => $entry['client_modified'],
                        'tags' => $tags
                    ];
                } elseif ($entry['.tag'] === 'folder') {
                    // Recursively get files from subfolders
                    $subFiles = $this->getAllDropboxFiles($client, $entry['path_display']);
                    $allFiles = array_merge($allFiles, $subFiles);
                }
            }
        } catch (\Exception $e) {
            // Handle pagination or other errors
            error_log('Error listing Dropbox folder ' . $path . ': ' . $e->getMessage());
        }
        
        return $allFiles;
    }

    /**
     * Convert folder path to tags
     * Examples:
     * - "/Apps/StarbugStoneComics/superHero" -> ["Super Hero"]
     * - "/Apps/StarbugStoneComics/Manga/Anime" -> ["Manga", "Anime"]
     * - "/Apps/StarbugStoneComics/sci-fi/space_opera" -> ["Sci Fi", "Space Opera"]
     */
    private function convertPathToTags(string $path): array
    {
        if (empty($path) || $path === '.') {
            return [];
        }
        
        // Remove the app folder prefix from the path to get only the user's folder structure
        $appFolderPrefix = trim($this->dropboxAppFolder, '/') . '/';
        $relativePath = ltrim($path, '/');
        
        if (str_starts_with($relativePath, $appFolderPrefix)) {
            $relativePath = substr($relativePath, strlen($appFolderPrefix));
        }
        
        if (empty($relativePath)) {
            return [];
        }
        
        $folders = explode('/', $relativePath);
        $tags = [];
        
        foreach ($folders as $folder) {
            if (!empty($folder)) {
                // Convert camelCase and snake_case to readable format
                $tag = $this->formatFolderName($folder);
                if (!empty($tag)) {
                    $tags[] = $tag;
                }
            }
        }
        
        return $tags;
    }

    /**
     * Format folder name to readable tag
     * Examples:
     * - "superHero" -> "Super Hero"
     * - "sci-fi" -> "Sci Fi"
     * - "space_opera" -> "Space Opera"
     * - "MANGA" -> "Manga"
     */
    private function formatFolderName(string $folderName): string
    {
        // Replace underscores and hyphens with spaces
        $formatted = str_replace(['_', '-'], ' ', $folderName);
        
        // Split camelCase
        $formatted = preg_replace('/([a-z])([A-Z])/', '$1 $2', $formatted);
        
        // Clean up multiple spaces
        $formatted = preg_replace('/\s+/', ' ', $formatted);
        
        // Trim and convert to title case
        $formatted = trim($formatted);
        $formatted = ucwords(strtolower($formatted));
        
        return $formatted;
    }
} 