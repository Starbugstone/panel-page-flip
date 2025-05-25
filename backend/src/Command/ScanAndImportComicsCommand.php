<?php

namespace App\Command;

use App\Entity\Comic;
use App\Entity\User;
use App\Service\ComicService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\Finder\Finder;
use Symfony\Component\String\Slugger\SluggerInterface;
use ZipArchive;
use RarArchive;

/**
 * Scans all user directories for CBZ/CBR files and imports them into the database if not already present.
 *
 * This command automatically scans all user directories in the comics_directory and imports any CBZ/CBR files
 * that are not already in the database. It's useful for bulk importing comics that have been manually copied
 * to user directories, bypassing the slow upload process.
 *
 * Usage Examples:
 * --------------
 *
 * 1. Running via Docker (from your project's root directory where docker-compose.yml is):
 *    docker exec cbz_reader_php php bin/console app:scan-import-comics
 *
 * 2. Running locally (if you have PHP and Composer installed directly on your machine and are in the `backend` directory):
 *    php bin/console app:scan-import-comics
 *
 * Options:
 *   --dry-run: If set, the command will only show what would be imported without actually importing anything.
 *   --user-id=ID: If set, only scan the directory for the specified user ID.
 *
 * Important Considerations:
 * - The command will skip files that are already in the database (based on filename and user).
 * - The command will generate titles from filenames automatically.
 * - The command will extract cover images and count pages for each comic.
 * - This is much faster than uploading through the web interface for large collections.
 */
#[AsCommand(
    name: 'app:scan-import-comics',
    description: 'Scans all user directories for CBZ/CBR files and imports them into the database if not already present.',
)]
class ScanAndImportComicsCommand extends Command
{
    private EntityManagerInterface $entityManager;
    private ParameterBagInterface $parameterBag;
    private SluggerInterface $slugger;
    private ComicService $comicService;

    public function __construct(
        EntityManagerInterface $entityManager,
        ParameterBagInterface $parameterBag,
        SluggerInterface $slugger,
        ComicService $comicService
    ) {
        parent::__construct();
        $this->entityManager = $entityManager;
        $this->parameterBag = $parameterBag;
        $this->slugger = $slugger;
        $this->comicService = $comicService;
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Only show what would be imported without actually importing')
            ->addOption('user-id', 'u', InputOption::VALUE_REQUIRED, 'Only scan the directory for the specified user ID');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = $input->getOption('dry-run');
        $userId = $input->getOption('user-id');
        
        $comicsDirectory = $this->parameterBag->get('comics_directory');
        
        if (!is_dir($comicsDirectory)) {
            $io->error(sprintf('Comics directory "%s" does not exist.', $comicsDirectory));
            return Command::FAILURE;
        }
        
        // Get all users or a specific user if user-id is provided
        if ($userId) {
            $users = [$this->entityManager->getRepository(User::class)->find($userId)];
            if (!$users[0]) {
                $io->error(sprintf('User with ID "%s" not found.', $userId));
                return Command::FAILURE;
            }
        } else {
            $users = $this->entityManager->getRepository(User::class)->findAll();
        }
        
        if (empty($users)) {
            $io->warning('No users found in the database.');
            return Command::SUCCESS;
        }
        
        $totalImported = 0;
        $totalSkipped = 0;
        $totalErrors = 0;
        
        foreach ($users as $user) {
            $userDirectory = $comicsDirectory . '/' . $user->getId();
            
            if (!is_dir($userDirectory)) {
                $io->note(sprintf('Directory for user ID %d does not exist, skipping.', $user->getId()));
                continue;
            }
            
            $io->section(sprintf('Scanning directory for user %s (ID: %d)', $user->getEmail(), $user->getId()));
            
            // Find all CBZ and CBR files in the user's directory
            $finder = new Finder();
            $finder->files()->name(['*.cbz', '*.cbr'])->in($userDirectory);
            
            if (!$finder->hasResults()) {
                $io->note(sprintf('No CBZ/CBR files found in directory for user ID %d.', $user->getId()));
                continue;
            }
            
            // Get existing comics for this user to check against
            $existingComics = $this->entityManager->getRepository(Comic::class)
                ->findBy(['owner' => $user]);
            
            $existingFilePaths = [];
            foreach ($existingComics as $comic) {
                $existingFilePaths[] = $comic->getFilePath();
            }
            
            $importedCount = 0;
            $skippedCount = 0;
            $errorCount = 0;
            
            foreach ($finder as $file) {
                $filename = $file->getFilename();
                $relativePath = $file->getRelativePathname();
                
                // Skip if this file is already in the database
                if (in_array($filename, $existingFilePaths)) {
                    $io->note(sprintf('Comic "%s" already exists in database, skipping.', $filename));
                    $skippedCount++;
                    continue;
                }
                
                try {
                    // Generate title from filename
                    $title = $this->generateTitleFromFilename($filename);
                    
                    $io->text(sprintf('Processing: "%s" as "%s"', $relativePath, $title));
                    
                    if ($dryRun) {
                        $io->text(sprintf('Would import: "%s" as "%s"', $relativePath, $title));
                        $importedCount++;
                        continue;
                    }
                    
                    // Create comic entity
                    $comic = new Comic();
                    $comic->setTitle($title);
                    $comic->setFilePath($filename);
                    $comic->setOwner($user);
                    
                    // Persist to get an ID (needed for cover organization)
                    $this->entityManager->persist($comic);
                    $this->entityManager->flush();
                    
                    // Extract cover image
                    $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
                    $coverImagePath = null;
                    
                    // Use the ComicService to extract cover image - it has better fallback mechanisms
                    try {
                        $coverImagePath = $this->comicService->extractCoverImage(
                            $userDirectory . '/' . $filename,
                            $user,
                            $comic->getId(),
                            $this->slugger->slug($title) . '-cover-' . uniqid()
                        );
                    } catch (\Exception $e) {
                        $io->warning(sprintf('Failed to extract cover image: %s', $e->getMessage()));
                        // Fallback to our own methods if ComicService fails
                        if ($extension === 'cbz') {
                            $coverImagePath = $this->extractCoverImageFromCbz(
                                $userDirectory . '/' . $filename,
                                $this->slugger->slug($title),
                                $comicsDirectory,
                                $user,
                                $comic->getId()
                            );
                        }
                    }
                    
                    // Count pages using ComicService
                    $pageCount = 0;
                    try {
                        $fullPath = $userDirectory . '/' . $filename;
                        
                        // Check if file exists
                        if (!file_exists($fullPath)) {
                            $io->error(sprintf('File does not exist: %s', $fullPath));
                            continue;
                        }
                        
                        // Use ComicService to count pages for both CBZ and CBR files
                        $pageCount = $this->comicService->countPages($fullPath);
                        $io->text(sprintf('Found %d pages in %s', $pageCount, $filename));
                    } catch (\Exception $e) {
                        $io->warning(sprintf('Failed to count pages: %s', $e->getMessage()));
                        // Fallback to our own method if ComicService fails
                        if ($extension === 'cbz') {
                            $pageCount = $this->countPagesInCbz($userDirectory . '/' . $filename);
                            $io->text(sprintf('Fallback method found %d pages in CBZ file', $pageCount));
                        }
                    }
                    
                    // Update the comic entity with additional information
                    $comic->setCoverImagePath($coverImagePath);
                    $comic->setPageCount($pageCount);
                    
                    // Save the updated comic
                    $this->entityManager->flush();
                    
                    $io->success(sprintf('Imported "%s" with %d pages.', $title, $pageCount));
                    $importedCount++;
                } catch (\Exception $e) {
                    $io->error(sprintf('Error importing "%s": %s', $relativePath, $e->getMessage()));
                    $errorCount++;
                }
            }
            
            $io->note(sprintf(
                'User %s: Imported %d, Skipped %d, Errors %d',
                $user->getEmail(),
                $importedCount,
                $skippedCount,
                $errorCount
            ));
            
            $totalImported += $importedCount;
            $totalSkipped += $skippedCount;
            $totalErrors += $errorCount;
        }
        
        $io->section('Import Summary');
        $io->listing([
            sprintf('Total Imported: %d comics', $totalImported),
            sprintf('Total Skipped: %d comics (already exist)', $totalSkipped),
            sprintf('Total Errors: %d comics', $totalErrors),
        ]);
        
        if ($dryRun) {
            $io->warning('This was a dry run. No comics were actually imported.');
        }
        
        return Command::SUCCESS;
    }
    
    /**
     * Generate a readable title from a filename
     */
    private function generateTitleFromFilename(string $filename): string
    {
        // Remove the .cbz or .cbr extension
        $title = preg_replace('/\.(cbz|cbr)$/i', '', $filename);
        
        // Handle snake_case
        $title = str_replace('_', ' ', $title);
        
        // Handle kebab-case
        $title = str_replace('-', ' ', $title);
        
        // Handle camelCase and PascalCase
        // Insert space before uppercase letters that are preceded by lowercase or digits
        $title = preg_replace('/([a-z0-9])([A-Z])/u', '$1 $2', $title);
        
        // Handle numbers with no spaces
        $title = preg_replace('/([a-zA-Z])([0-9])/u', '$1 $2', $title);
        $title = preg_replace('/([0-9])([a-zA-Z])/u', '$1 $2', $title);
        
        // Replace multiple spaces with a single space
        $title = preg_replace('/\s+/', ' ', $title);
        
        // Capitalize first letter of each word
        $title = ucwords($title);
        
        return trim($title);
    }
    
    /**
     * Extract the cover image from a CBZ file
     */
    private function extractCoverImageFromCbz(string $cbzPath, string $baseFilename, string $outputDir, User $user, int $comicId): ?string
    {
        $zip = new ZipArchive();
        if ($zip->open($cbzPath) !== true) {
            return null;
        }

        // Get all image files from the archive
        $imageFiles = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $filename = $zip->getNameIndex($i);
            $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
            if (in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp'])) {
                $imageFiles[] = $filename;
            }
        }

        // Sort image files naturally (1, 2, 10 instead of 1, 10, 2)
        usort($imageFiles, 'strnatcmp');

        // Use the first image as cover
        if (empty($imageFiles)) {
            $zip->close();
            return null;
        }

        $coverImage = $imageFiles[0];
        $coverExtension = strtolower(pathinfo($coverImage, PATHINFO_EXTENSION));
        
        // Organize covers by comic ID
        $coverSubDir = 'covers/' . $comicId;
        $coverPath = $outputDir . '/' . $user->getId() . '/' . $coverSubDir;
        $coverFilename = $baseFilename . '-cover-' . uniqid() . '.' . $coverExtension;

        // Create covers directory if it doesn't exist
        if (!file_exists($coverPath)) {
            mkdir($coverPath, 0777, true);
        }

        // Extract cover image
        $coverData = $zip->getFromName($coverImage);
        file_put_contents($coverPath . '/' . $coverFilename, $coverData);
        $zip->close();

        return $coverSubDir . '/' . $coverFilename;
    }
    
    /**
     * Extract the cover image from a CBR file
     */
    private function extractCoverImageFromCbr(string $cbrPath, string $baseFilename, string $outputDir, User $user, int $comicId): ?string
    {
        // Check if RarArchive extension is available
        if (!extension_loaded('rar')) {
            // Fall back to shell commands
            return $this->extractCoverImageFromCbrUsingShell($cbrPath, $baseFilename, $outputDir, $user, $comicId);
        }
        
        try {
            $rar = RarArchive::open($cbrPath);
            if (!$rar) {
                return null;
            }
            
            // Get all entries
            $entries = $rar->getEntries();
            if (!$entries) {
                $rar->close();
                return null;
            }
            
            // Filter image files
            $imageFiles = [];
            foreach ($entries as $entry) {
                $filename = $entry->getName();
                $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
                if (in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp'])) {
                    $imageFiles[] = $filename;
                }
            }
            
            // Sort image files naturally
            usort($imageFiles, 'strnatcmp');
            
            // Use the first image as cover
            if (empty($imageFiles)) {
                $rar->close();
                return null;
            }
            
            $coverImage = $imageFiles[0];
            $coverExtension = strtolower(pathinfo($coverImage, PATHINFO_EXTENSION));
            
            // Organize covers by comic ID
            $coverSubDir = 'covers/' . $comicId;
            $coverPath = $outputDir . '/' . $user->getId() . '/' . $coverSubDir;
            $coverFilename = $baseFilename . '-cover-' . uniqid() . '.' . $coverExtension;
            
            // Create covers directory if it doesn't exist
            if (!file_exists($coverPath)) {
                mkdir($coverPath, 0777, true);
            }
            
            // Extract cover image
            $entry = $rar->getEntry($coverImage);
            $stream = $entry->getStream();
            $coverData = stream_get_contents($stream);
            file_put_contents($coverPath . '/' . $coverFilename, $coverData);
            $rar->close();
            
            return $coverSubDir . '/' . $coverFilename;
        } catch (\Exception $e) {
            // Fall back to shell commands if RarArchive fails
            return $this->extractCoverImageFromCbrUsingShell($cbrPath, $baseFilename, $outputDir, $user, $comicId);
        }
    }
    
    /**
     * Extract the cover image from a CBR file using shell commands (fallback method)
     */
    private function extractCoverImageFromCbrUsingShell(string $cbrPath, string $baseFilename, string $outputDir, User $user, int $comicId): ?string
    {
        // Create a temporary directory for extraction
        $tempDir = sys_get_temp_dir() . '/cbr_extract_' . uniqid();
        if (!file_exists($tempDir)) {
            mkdir($tempDir, 0777, true);
        }
        
        // Try unrar first
        $success = false;
        exec('which unrar 2>/dev/null', $output, $returnCode);
        if ($returnCode === 0) {
            // unrar is available
            exec('unrar e -o+ "' . $cbrPath . '" "' . $tempDir . '" *.jpg *.jpeg *.png *.gif *.webp 2>/dev/null', $output, $returnCode);
            $success = $returnCode === 0;
        }
        
        // If unrar failed, try 7z
        if (!$success) {
            exec('which 7z 2>/dev/null', $output, $returnCode);
            if ($returnCode === 0) {
                // 7z is available
                exec('7z e -y "' . $cbrPath . '" -o"' . $tempDir . '" *.jpg *.jpeg *.png *.gif *.webp 2>/dev/null', $output, $returnCode);
                $success = $returnCode === 0;
            }
        }
        
        if (!$success) {
            // Clean up temp directory
            $this->cleanupTempDirectory($tempDir);
            return null;
        }
        
        // Find all extracted image files
        $finder = new Finder();
        $finder->files()->name(['*.jpg', '*.jpeg', '*.png', '*.gif', '*.webp'])->in($tempDir);
        
        if (!$finder->hasResults()) {
            // Clean up temp directory
            $this->cleanupTempDirectory($tempDir);
            return null;
        }
        
        // Sort files by name
        $imageFiles = [];
        foreach ($finder as $file) {
            $imageFiles[$file->getFilename()] = $file->getRealPath();
        }
        ksort($imageFiles, SORT_NATURAL);
        
        // Use the first image as cover
        $coverPath = reset($imageFiles);
        $coverExtension = strtolower(pathinfo($coverPath, PATHINFO_EXTENSION));
        
        // Organize covers by comic ID
        $coverSubDir = 'covers/' . $comicId;
        $userCoverPath = $outputDir . '/' . $user->getId() . '/' . $coverSubDir;
        $coverFilename = $baseFilename . '-cover-' . uniqid() . '.' . $coverExtension;
        
        // Create covers directory if it doesn't exist
        if (!file_exists($userCoverPath)) {
            mkdir($userCoverPath, 0777, true);
        }
        
        // Copy cover image
        copy($coverPath, $userCoverPath . '/' . $coverFilename);
        
        // Clean up temp directory
        $this->cleanupTempDirectory($tempDir);
        
        return $coverSubDir . '/' . $coverFilename;
    }
    
    /**
     * Count the number of pages in a CBZ file
     */
    private function countPagesInCbz(string $cbzPath): int
    {
        $zip = new ZipArchive();
        if ($zip->open($cbzPath) !== true) {
            return 0;
        }

        // Count image files
        $pageCount = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $filename = $zip->getNameIndex($i);
            $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
            if (in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp'])) {
                $pageCount++;
            }
        }

        $zip->close();
        return $pageCount;
    }
    
    /**
     * Count the number of pages in a CBR file
     */
    private function countPagesInCbr(string $cbrPath): int
    {
        // Check if RarArchive extension is available
        if (!extension_loaded('rar')) {
            // Fall back to shell commands
            return $this->countPagesInCbrUsingShell($cbrPath);
        }
        
        try {
            $rar = RarArchive::open($cbrPath);
            if (!$rar) {
                return 0;
            }
            
            // Get all entries
            $entries = $rar->getEntries();
            if (!$entries) {
                $rar->close();
                return 0;
            }
            
            // Count image files
            $pageCount = 0;
            foreach ($entries as $entry) {
                $filename = $entry->getName();
                $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
                if (in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp'])) {
                    $pageCount++;
                }
            }
            
            $rar->close();
            return $pageCount;
        } catch (\Exception $e) {
            // Fall back to shell commands if RarArchive fails
            return $this->countPagesInCbrUsingShell($cbrPath);
        }
    }
    
    /**
     * Count the number of pages in a CBR file using shell commands (fallback method)
     */
    private function countPagesInCbrUsingShell(string $cbrPath): int
    {
        // Try unrar first
        exec('which unrar 2>/dev/null', $output, $returnCode);
        if ($returnCode === 0) {
            // unrar is available
            exec('unrar lb "' . $cbrPath . '" | grep -iE "\.(jpg|jpeg|png|gif|webp)$" | wc -l', $output, $returnCode);
            if ($returnCode === 0 && isset($output[0])) {
                return (int)$output[0];
            }
        }
        
        // If unrar failed, try 7z
        exec('which 7z 2>/dev/null', $output, $returnCode);
        if ($returnCode === 0) {
            // 7z is available
            exec('7z l "' . $cbrPath . '" | grep -iE "\.(jpg|jpeg|png|gif|webp)$" | wc -l', $output, $returnCode);
            if ($returnCode === 0 && isset($output[0])) {
                return (int)$output[0];
            }
        }
        
        // If all methods failed, return 0
        return 0;
    }
    
    /**
     * Helper method to clean up a temporary directory
     */
    private function cleanupTempDirectory(string $directory): void
    {
        if (!file_exists($directory)) {
            return;
        }
        
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        
        foreach ($files as $file) {
            if ($file->isDir()) {
                rmdir($file->getRealPath());
            } else {
                unlink($file->getRealPath());
            }
        }
        
        rmdir($directory);
    }
}
