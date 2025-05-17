<?php

namespace App\Command;

use App\Entity\Comic;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\Finder\Finder;

/**
 * Cleans up unused comic files and orphaned cover images.
 *
 * This command helps maintain disk space by removing:
 * 1. Comic files that are no longer referenced in the database
 * 2. Cover images that are no longer referenced in the database
 * 3. Optionally, comics that haven't been accessed in a specified period
 *
 * Usage Examples:
 * --------------
 *
 * 1. Running via Docker (from your project's root directory where docker-compose.yml is):
 *    docker exec panel-page-flip_php php bin/console app:cleanup-comics
 *
 *    To also remove comics not accessed in the last 90 days:
 *    docker exec panel-page-flip_php php bin/console app:cleanup-comics --days=90
 *
 * 2. Running locally (if you have PHP and Composer installed directly on your machine and are in the `backend` directory):
 *    php bin/console app:cleanup-comics
 *
 * Options:
 *   --days=N           Remove comics that haven't been accessed in N days
 *   --dry-run          Show what would be deleted without actually deleting
 *   --force            Skip confirmation prompt
 *
 * Important Considerations:
 * - This command will permanently delete files from the filesystem
 * - By default, it will only remove files that are no longer referenced in the database
 * - Use --dry-run to see what would be deleted before actually deleting
 * - The command will ask for confirmation before deleting files unless --force is used
 */
#[AsCommand(
    name: 'app:cleanup-comics',
    description: 'Cleans up unused comic files and orphaned cover images.',
)]
class CleanupComicsCommand extends Command
{
    private EntityManagerInterface $entityManager;
    private ParameterBagInterface $parameterBag;

    public function __construct(
        EntityManagerInterface $entityManager,
        ParameterBagInterface $parameterBag
    ) {
        parent::__construct();
        $this->entityManager = $entityManager;
        $this->parameterBag = $parameterBag;
    }

    protected function configure(): void
    {
        $this
            ->addOption('days', null, InputOption::VALUE_REQUIRED, 'Remove comics that haven\'t been accessed in this many days')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would be deleted without actually deleting')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Skip confirmation prompt');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = $input->getOption('dry-run');
        $force = $input->getOption('force');
        $days = $input->getOption('days');
        
        $comicsDirectory = $this->parameterBag->get('comics_directory');
        if (!is_dir($comicsDirectory)) {
            $io->error(sprintf('Comics directory "%s" does not exist.', $comicsDirectory));
            return Command::FAILURE;
        }
        
        $coversDirectory = $comicsDirectory . '/covers';
        
        // 1. Find all comic files in the database
        $dbComics = $this->entityManager->getRepository(Comic::class)->findAll();
        $dbComicFiles = [];
        $dbCoverFiles = [];
        
        foreach ($dbComics as $comic) {
            $dbComicFiles[] = $comic->getFilePath();
            if ($comic->getCoverImagePath()) {
                // Extract just the filename from the path (e.g., "covers/filename.jpg" -> "filename.jpg")
                $coverFile = basename($comic->getCoverImagePath());
                $dbCoverFiles[] = $coverFile;
            }
        }
        
        // 2. Find all comic files on disk, including in user subdirectories
        $diskComicFiles = [];
        $finder = new Finder();
        
        try {
            // First check the root comics directory (old structure)
            if (is_dir($comicsDirectory)) {
                $rootFinder = new Finder();
                $rootFinder->files()->name('*.cbz')->in($comicsDirectory)->depth(0);
                
                foreach ($rootFinder as $file) {
                    $diskComicFiles[] = [
                        'filename' => $file->getFilename(),
                        'path' => $file->getRealPath(),
                        'user_id' => null
                    ];
                }
            }
            
            // Then check user subdirectories (new structure)
            $userDirFinder = new Finder();
            $userDirFinder->directories()->in($comicsDirectory)->depth(0);
            
            foreach ($userDirFinder as $userDir) {
                // Skip the covers directory
                if ($userDir->getFilename() === 'covers') {
                    continue;
                }
                
                // Check if this is a numeric directory (user ID)
                if (is_numeric($userDir->getFilename())) {
                    $userId = (int) $userDir->getFilename();
                    $userComicsFinder = new Finder();
                    $userComicsFinder->files()->name('*.cbz')->in($userDir->getRealPath())->depth(0);
                    
                    foreach ($userComicsFinder as $file) {
                        $diskComicFiles[] = [
                            'filename' => $file->getFilename(),
                            'path' => $file->getRealPath(),
                            'user_id' => $userId
                        ];
                    }
                }
            }
        } catch (\Exception $e) {
            // Directory might not exist yet
            $io->note(sprintf('Could not scan comics directory: %s', $e->getMessage()));
        }
        
        // 3. Find all cover files on disk, including in comic-specific subdirectories
        $diskCoverFiles = [];
        
        try {
            if (is_dir($coversDirectory)) {
                // First check the root covers directory (old structure)
                $rootCoversFinder = new Finder();
                $rootCoversFinder->files()->in($coversDirectory)->depth(0);
                
                foreach ($rootCoversFinder as $file) {
                    $diskCoverFiles[] = [
                        'filename' => $file->getFilename(),
                        'path' => $file->getRealPath(),
                        'comic_id' => null
                    ];
                }
                
                // Then check comic-specific subdirectories (new structure)
                $comicDirFinder = new Finder();
                try {
                    $comicDirFinder->directories()->in($coversDirectory)->depth(0);
                    
                    foreach ($comicDirFinder as $comicDir) {
                        // Check if this is a numeric directory (comic ID)
                        if (is_numeric($comicDir->getFilename())) {
                            $comicId = (int) $comicDir->getFilename();
                            $comicCoversFinder = new Finder();
                            $comicCoversFinder->files()->in($comicDir->getRealPath())->depth(0);
                            
                            foreach ($comicCoversFinder as $file) {
                                $diskCoverFiles[] = [
                                    'filename' => $file->getFilename(),
                                    'path' => $file->getRealPath(),
                                    'comic_id' => $comicId
                                ];
                            }
                        }
                    }
                } catch (\Exception $e) {
                    // No comic subdirectories yet
                    $io->note('No comic-specific cover directories found.');
                }
            }
        } catch (\Exception $e) {
            // Covers directory might not exist yet
            $io->note(sprintf('Could not scan covers directory: %s', $e->getMessage()));
        }
        
        // 4. Find orphaned files (on disk but not in database)
        $orphanedComics = [];
        $orphanedCovers = [];
        
        // Check for orphaned comics
        foreach ($diskComicFiles as $diskComic) {
            $filename = $diskComic['filename'];
            $userId = $diskComic['user_id'];
            
            // For comics in user directories, check if the user has this comic
            $found = false;
            foreach ($dbComics as $dbComic) {
                if ($dbComic->getFilePath() === $filename) {
                    // If the comic is in a user directory, make sure it belongs to that user
                    if ($userId === null || $dbComic->getOwner()->getId() === $userId) {
                        $found = true;
                        break;
                    }
                }
            }
            
            if (!$found) {
                $orphanedComics[] = $diskComic;
            }
        }
        
        // Check for orphaned covers
        foreach ($diskCoverFiles as $diskCover) {
            $filename = $diskCover['filename'];
            $comicId = $diskCover['comic_id'];
            $path = $diskCover['path'];
            
            // For covers in comic-specific directories, check if the comic exists and uses this cover
            $found = false;
            foreach ($dbComics as $dbComic) {
                $coverPath = $dbComic->getCoverImagePath();
                if ($coverPath) {
                    // Check if the cover path matches this file
                    if (basename($coverPath) === $filename) {
                        // If the cover is in a comic-specific directory, make sure it belongs to that comic
                        if ($comicId === null || $dbComic->getId() === $comicId) {
                            $found = true;
                            break;
                        }
                    }
                }
            }
            
            if (!$found) {
                $orphanedCovers[] = $diskCover;
            }
        }
        
        // 5. If days option is provided, find old comics
        $oldComics = [];
        if ($days !== null) {
            $cutoffDate = new \DateTime("-{$days} days");
            
            $oldComicsEntities = $this->entityManager->getRepository(Comic::class)
                ->createQueryBuilder('c')
                ->leftJoin('c.readingProgress', 'rp')
                ->where('rp.lastReadAt < :cutoffDate OR rp.lastReadAt IS NULL')
                ->andWhere('c.uploadedAt < :cutoffDate')
                ->setParameter('cutoffDate', $cutoffDate)
                ->getQuery()
                ->getResult();
            
            foreach ($oldComicsEntities as $comic) {
                $oldComics[] = [
                    'id' => $comic->getId(),
                    'title' => $comic->getTitle(),
                    'filePath' => $comic->getFilePath(),
                    'coverPath' => $comic->getCoverImagePath(),
                    'uploadedAt' => $comic->getUploadedAt()->format('Y-m-d'),
                ];
            }
        }
        
        // 6. Display summary
        $io->title('Cleanup Summary');
        
        if (empty($orphanedComics) && empty($orphanedCovers) && empty($oldComics)) {
            $io->success('No files to clean up.');
            return Command::SUCCESS;
        }
        
        if (!empty($orphanedComics)) {
            $io->section('Orphaned Comic Files');
            $orphanedComicsList = array_map(function ($comic) {
                $location = $comic['user_id'] ? "User ID: {$comic['user_id']}" : 'Root directory';
                return "{$comic['filename']} ({$location})";
            }, $orphanedComics);
            $io->listing($orphanedComicsList);
        }
        
        if (!empty($orphanedCovers)) {
            $io->section('Orphaned Cover Images');
            $orphanedCoversList = array_map(function ($cover) {
                $location = $cover['comic_id'] ? "Comic ID: {$cover['comic_id']}" : 'Root covers directory';
                return "{$cover['filename']} ({$location})";
            }, $orphanedCovers);
            $io->listing($orphanedCoversList);
        }
        
        if (!empty($oldComics)) {
            $io->section(sprintf('Comics Not Accessed in %d Days', $days));
            $oldComicsList = array_map(function ($comic) {
                return sprintf('%s (ID: %d, Uploaded: %s)', $comic['title'], $comic['id'], $comic['uploadedAt']);
            }, $oldComics);
            $io->listing($oldComicsList);
        }
        
        // 7. Confirm deletion if not in dry-run mode
        if (!$dryRun) {
            if (!$force && !$io->confirm('Do you want to proceed with deletion?', false)) {
                $io->warning('Operation cancelled.');
                return Command::SUCCESS;
            }
            
            // 8. Delete orphaned files
            $deletedComics = 0;
            foreach ($orphanedComics as $comic) {
                $filepath = $comic['path'];
                if (file_exists($filepath) && unlink($filepath)) {
                    $deletedComics++;
                }
            }
            
            $deletedCovers = 0;
            foreach ($orphanedCovers as $cover) {
                $filepath = $cover['path'];
                if (file_exists($filepath) && unlink($filepath)) {
                    $deletedCovers++;
                }
            }
            
            // 9. Delete old comics if requested
            $deletedOldComics = 0;
            if (!empty($oldComics)) {
                foreach ($oldComics as $comic) {
                    // Delete the comic file
                    $comicPath = $comicsDirectory . '/' . $comic['filePath'];
                    if (file_exists($comicPath)) {
                        unlink($comicPath);
                    }
                    
                    // Delete the cover file if it exists
                    if ($comic['coverPath']) {
                        $coverFilename = basename($comic['coverPath']);
                        $coverPath = $coversDirectory . '/' . $coverFilename;
                        if (file_exists($coverPath)) {
                            unlink($coverPath);
                        }
                    }
                    
                    // Delete from database
                    $comicEntity = $this->entityManager->getRepository(Comic::class)->find($comic['id']);
                    if ($comicEntity) {
                        $this->entityManager->remove($comicEntity);
                        $deletedOldComics++;
                    }
                }
                
                $this->entityManager->flush();
            }
            
            // 10. Display results
            $io->success([
                sprintf('Deleted %d orphaned comic files.', $deletedComics),
                sprintf('Deleted %d orphaned cover images.', $deletedCovers),
                sprintf('Deleted %d old comics.', $deletedOldComics),
            ]);
        } else {
            $io->note('This was a dry run. No files were deleted.');
        }
        
        return Command::SUCCESS;
    }
}
