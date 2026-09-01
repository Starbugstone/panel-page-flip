<?php

namespace App\Command;

use App\Repository\UserRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Creates the necessary upload directories for the application.
 *
 * This command creates the directories needed to store uploaded comics and their cover images.
 * It should be run during the initial setup of the application or when deploying to a new environment.
 *
 * Usage Examples:
 * --------------
 *
 * 1. Running via Docker (from your project's root directory where docker-compose.yml is):
 *    docker exec panel-page-flip_php php bin/console app:setup-upload-directories
 *
 *    Replace `panel-page-flip_php` with the actual name of your PHP service container if different.
 *
 * 2. Running locally (if you have PHP and Composer installed directly on your machine and are in the `backend` directory):
 *    php bin/console app:setup-upload-directories
 *
 *    Ensure your local environment is configured correctly.
 *
 * The command will create the following directories:
 * - public/uploads/comics - For storing uploaded CBZ files
 * - public/uploads/comics/covers - For storing extracted cover images
 * - public/uploads/comics/{user_id} - User-specific directories for each user's comics
 *
 * It will also set the appropriate permissions on these directories to ensure they are writable
 * by the web server.
 */
#[AsCommand(
    name: 'app:setup-upload-directories',
    description: 'Creates the necessary upload directories for the application.',
)]
class SetupUploadDirectoriesCommand extends Command
{
    public function __construct(
        #[Autowire('%comics_directory%')]
        private readonly string $comicsDirectory,
        private readonly UserRepository $users
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        
        if (!$this->createDirectory($this->comicsDirectory, $io)
            || !$this->createDirectory($this->comicsDirectory.'/covers', $io)) {
            return Command::FAILURE;
        }

        $users = $this->users->findAll();
        $io->note(sprintf('Creating user-specific directories for %d users', count($users)));

        foreach ($users as $user) {
            $id = $user->getId();
            if ($id === null || !$this->createDirectory($this->comicsDirectory.'/'.$id, $io)) {
                return Command::FAILURE;
            }
        }

        $io->success('Upload directories created successfully.');

        return Command::SUCCESS;
    }

    private function createDirectory(string $directory, SymfonyStyle $io): bool
    {
        if (!file_exists($directory)) {
            $io->note(sprintf('Creating directory: %s', $directory));
            if (!@mkdir($directory, 0775, true) && !is_dir($directory)) {
                $io->error(sprintf('Failed to create directory: %s', $directory));

                return false;
            }
        } else {
            $io->note(sprintf('Directory already exists: %s', $directory));
        }

        // Ensure directory is writable by the owner and group (PHP-FPM and the
        // web server share a group; world-writable uploads are not needed).
        if (!is_writable($directory)) {
            $io->note(sprintf('Setting permissions on directory: %s', $directory));
            if (!@chmod($directory, 0775)) {
                $io->error(sprintf('Failed to make directory writable: %s', $directory));

                return false;
            }
        }

        return true;
    }
}
