<?php

namespace App\Command;

use App\Entity\Comic;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\Finder\Finder;
use Symfony\Component\String\Slugger\SluggerInterface;
use ZipArchive;

/**
 * Imports CBZ comic files from a directory into the application.
 *
 * This command allows you to bulk import CBZ files from a specified directory.
 * It will extract cover images, count pages, and associate the comics with a specified user.
 *
 * Usage Examples:
 * --------------
 *
 * 1. Running via Docker (from your project's root directory where docker-compose.yml is):
 *    docker exec panel-page-flip_php php bin/console app:import-comics /path/to/comics admin@example.com
 *
 *    Replace `panel-page-flip_php` with the actual name of your PHP service container if different.
 *    Replace `/path/to/comics` with the path to the directory containing CBZ files.
 *    Replace `admin@example.com` with the email of the user who will own the imported comics.
 *
 * 2. Running locally (if you have PHP and Composer installed directly on your machine and are in the `backend` directory):
 *    php bin/console app:import-comics /path/to/comics admin@example.com
 *
 * Arguments:
 *   directory:  (Required) The directory containing CBZ files to import.
 *   user_email: (Required) The email of the user who will own the imported comics.
 *
 * Options:
 *   --recursive: If set, the command will search for CBZ files recursively in subdirectories.
 *
 * Important Considerations:
 * - The command will skip files that are not CBZ files.
 * - The command will skip files that already exist in the database (based on filename).
 * - The command will extract cover images from the CBZ files and store them in the covers directory.
 * - The command will count the number of pages in each CBZ file.
 */
#[AsCommand(
    name: 'app:import-comics',
    description: 'Imports CBZ comic files from a directory into the application.',
)]
class ImportComicsCommand extends Command
{
    private EntityManagerInterface $entityManager;
    private ParameterBagInterface $parameterBag;
    private SluggerInterface $slugger;

    public function __construct(
        EntityManagerInterface $entityManager,
        ParameterBagInterface $parameterBag,
        SluggerInterface $slugger
    ) {
        parent::__construct();
        $this->entityManager = $entityManager;
        $this->parameterBag = $parameterBag;
        $this->slugger = $slugger;
    }

    protected function configure(): void
    {
        $this
            ->addArgument('directory', InputArgument::REQUIRED, 'Directory containing CBZ files to import')
            ->addArgument('user_email', InputArgument::REQUIRED, 'Email of the user who will own the imported comics')
            ->addOption('recursive', 'r', InputOption::VALUE_NONE, 'Search for CBZ files recursively in subdirectories');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $directory = $input->getArgument('directory');
        $userEmail = $input->getArgument('user_email');
        $recursive = $input->getOption('recursive');

        // Validate directory
        if (!is_dir($directory)) {
            $io->error(sprintf('Directory "%s" does not exist.', $directory));
            return Command::FAILURE;
        }

        // Find user
        $user = $this->entityManager->getRepository(User::class)->findOneBy(['email' => $userEmail]);
        if (!$user) {
            $io->error(sprintf('User with email "%s" not found.', $userEmail));
            return Command::FAILURE;
        }

        // Find CBZ files
        $finder = new Finder();
        $finder->files()->name('*.cbz');
        
        if ($recursive) {
            $finder->in($directory);
        } else {
            $finder->in($directory)->depth(0);
        }

        if (!$finder->hasResults()) {
            $io->warning(sprintf('No CBZ files found in directory "%s".', $directory));
            return Command::SUCCESS;
        }

        $comicsDirectory = $this->parameterBag->get('comics_directory');
        $importedCount = 0;
        $skippedCount = 0;
        $errorCount = 0;

        foreach ($finder as $file) {
            $io->section(sprintf('Processing %s', $file->getRelativePathname()));
            
            try {
                $originalFilename = $file->getFilename();
                $title = pathinfo($originalFilename, PATHINFO_FILENAME);
                
                // Check if comic already exists
                $existingComic = $this->entityManager->getRepository(Comic::class)
                    ->findOneBy(['filePath' => $originalFilename, 'owner' => $user]);
                
                if ($existingComic) {
                    $io->note(sprintf('Comic "%s" already exists, skipping.', $title));
                    $skippedCount++;
                    continue;
                }
                
                // Create safe filename
                $safeFilename = $this->slugger->slug($title);
                $newFilename = $safeFilename . '-' . uniqid() . '.cbz';
                
                // Create user directory if it doesn't exist
                $userDirectory = $comicsDirectory . '/' . $user->getId();
                if (!is_dir($userDirectory)) {
                    mkdir($userDirectory, 0777, true);
                }
                
                // Copy file to user's comics directory
                copy($file->getRealPath(), $userDirectory . '/' . $newFilename);
                
                // Create comic entity first to get the ID
                $comic = new Comic();
                $comic->setTitle($title);
                $comic->setFilePath($newFilename);
                $comic->setOwner($user);
                
                // Persist to get an ID (needed for cover organization)
                $this->entityManager->persist($comic);
                $this->entityManager->flush();
                
                // Extract cover image with comic ID for organization
                $coverImagePath = $this->extractCoverImage(
                    $userDirectory . '/' . $newFilename,
                    $safeFilename,
                    $comicsDirectory,
                    $comic->getId()
                );
                
                // Count pages from the user directory
                $pageCount = $this->countPagesInCbz($userDirectory . '/' . $newFilename);
                
                // Update the comic entity with additional information
                $comic->setCoverImagePath($coverImagePath);
                $comic->setPageCount($pageCount);
                
                // Save the updated comic
                $this->entityManager->flush();
                
                $io->success(sprintf('Imported "%s" with %d pages.', $title, $pageCount));
                $importedCount++;
            } catch (\Exception $e) {
                $io->error(sprintf('Error importing "%s": %s', $file->getRelativePathname(), $e->getMessage()));
                $errorCount++;
            }
        }
        
        $io->section('Import Summary');
        $io->listing([
            sprintf('Imported: %d comics', $importedCount),
            sprintf('Skipped: %d comics (already exist)', $skippedCount),
            sprintf('Errors: %d comics', $errorCount),
        ]);
        
        return Command::SUCCESS;
    }
    
    /**
     * Extract the cover image from a CBZ file
     * 
     * @param string $cbzPath Path to the CBZ file
     * @param string $baseFilename Base filename for the cover image
     * @param string $outputDir Base output directory
     * @param int|null $comicId Optional comic ID for organizing covers
     * @return string|null Path to the extracted cover image, relative to the output directory
     */
    private function extractCoverImage(string $cbzPath, string $baseFilename, string $outputDir, ?int $comicId = null): ?string
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
        
        // Organize covers by comic ID if available
        $coverSubDir = 'covers';
        if ($comicId !== null) {
            $coverSubDir .= '/' . $comicId;
        }
        
        $coverPath = $outputDir . '/' . $coverSubDir;
        $coverFilename = $baseFilename . '-cover-' . uniqid() . '.' . $coverExtension;

        // Create covers directory if it doesn't exist
        if (!file_exists($coverPath)) {
            mkdir($coverPath, 0777, true);
        }

        // Extract cover image
        $coverData = $zip->getFromName($coverImage);
        file_put_contents($coverPath . '/' . $coverFilename, $coverData);
        $zip->close();

        // TODO: Implement a proper CBZ reader to extract and process the first page as cover
        // This would involve parsing the CBZ file format and extracting the first page
        // For now, we're just using the first image file found in the archive

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
}
