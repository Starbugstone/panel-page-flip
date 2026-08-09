<?php

namespace App\Command;

use App\Entity\Comic;
use App\Entity\User;
use App\Service\ComicFormatService;
use App\Service\ComicService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Finder\Finder;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Imports enabled comic source files from a directory into the application.
 *
 * Files go through ComicService, so directory imports use the same format,
 * quota, safety, page-count and cover checks as browser and Dropbox uploads.
 *
 * Usage Examples:
 * --------------
 *
 * 1. Running via Docker (from your project's root directory where docker-compose.yml is):
 *    docker exec panel-page-flip_php php bin/console app:import-comics /path/to/comics admin@example.com
 *
 *    Replace `panel-page-flip_php` with the actual name of your PHP service container if different.
 *    Replace `/path/to/comics` with the path containing comic source files.
 *    Replace `admin@example.com` with the email of the user who will own the imported comics.
 *
 * 2. Running locally (if you have PHP and Composer installed directly on your machine and are in the `backend` directory):
 *    php bin/console app:import-comics /path/to/comics admin@example.com
 *
 * Arguments:
 *   directory:  (Required) The directory containing comic sources to import.
 *   user_email: (Required) The email of the user who will own the imported comics.
 *
 * Options:
 *   --recursive: Search for enabled comic formats recursively.
 *
 * Important Considerations:
 * - Disabled and unsupported source formats are skipped.
 * - Existing owner/title pairs are skipped.
 * - Cover and page processing uses the same provider pipeline as uploads.
 */
#[AsCommand(
    name: 'app:import-comics',
    description: 'Imports enabled comic source files from a directory into the application.',
)]
class ImportComicsCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ComicService $comicService,
        private readonly ComicFormatService $comicFormatService
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('directory', InputArgument::REQUIRED, 'Directory containing comic source files to import')
            ->addArgument('user_email', InputArgument::REQUIRED, 'Email of the user who will own the imported comics')
            ->addOption('recursive', 'r', InputOption::VALUE_NONE, 'Search for comic source files recursively in subdirectories');
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

        $enabledExtensions = array_map(
            static fn ($type): string => $type->value,
            array_values(array_filter($this->comicFormatService->enabled(), $this->comicFormatService->isEnabled(...)))
        );
        $extensionPattern = '/\.(' . implode('|', array_map('preg_quote', $enabledExtensions)) . ')$/i';
        $finder = new Finder();
        $finder->files()->name($extensionPattern);
        
        if ($recursive) {
            $finder->in($directory);
        } else {
            $finder->in($directory)->depth(0);
        }

        if (!$finder->hasResults()) {
            $io->warning(sprintf('No enabled comic source files found in directory "%s".', $directory));
            return Command::SUCCESS;
        }

        $importedCount = 0;
        $skippedCount = 0;
        $errorCount = 0;
        $abortedEarly = false;

        foreach ($finder as $file) {
            $io->section(sprintf('Processing %s', $file->getRelativePathname()));
            
            try {
                $originalFilename = $file->getFilename();
                $title = pathinfo($originalFilename, PATHINFO_FILENAME);
                
                // Legacy imports did not persist their original source path, so
                // title + owner remains the only stable duplicate key here.
                $existingComic = $this->entityManager->getRepository(Comic::class)
                    ->findOneBy(['title' => $title, 'owner' => $user]);
                
                if ($existingComic) {
                    $io->note(sprintf('Comic "%s" already exists, skipping.', $title));
                    $skippedCount++;
                    continue;
                }
                
                $comic = $this->comicService->uploadComic(
                    new UploadedFile(
                        $file->getRealPath(),
                        $originalFilename,
                        mime_content_type($file->getRealPath()) ?: 'application/octet-stream',
                        null,
                        true
                    ),
                    $user,
                    $title
                );
                
                $io->success(sprintf('Imported "%s" with %d pages.', $title, $comic->getPageCount()));
                $importedCount++;
            } catch (\Exception $e) {
                $io->error(sprintf('Error importing "%s": %s', $file->getRelativePathname(), $e->getMessage()));
                $errorCount++;

                // Doctrine closes the entity manager when a flush fails, and
                // nothing reopens it here. Carrying on would report every
                // remaining file as broken when the only thing broken is the
                // connection, so stop while the summary still means something.
                if (!$this->entityManager->isOpen()) {
                    $io->error('The entity manager closed after that failure; stopping so the remaining files are not all reported as errors.');
                    $abortedEarly = true;
                    break;
                }
            }
        }
        
        $io->section('Import Summary');
        $io->listing([
            sprintf('Imported: %d comics', $importedCount),
            sprintf('Skipped: %d comics (already exist)', $skippedCount),
            sprintf('Errors: %d comics', $errorCount),
        ]);

        // A run that stopped on a dead connection left files untouched that it
        // was asked to import. Reporting success would tell cron and CI the
        // library is up to date when it is not.
        if ($abortedEarly) {
            $io->error('The import stopped early and did not process every file. Re-run it once the database is healthy.');
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
