<?php

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

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
    private ParameterBagInterface $parameterBag;

    public function __construct(ParameterBagInterface $parameterBag)
    {
        parent::__construct();
        $this->parameterBag = $parameterBag;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        
        // Get the comics directory from parameters
        $comicsDirectory = $this->parameterBag->get('comics_directory');
        $coversDirectory = $comicsDirectory . '/covers';
        
        // Create directories if they don't exist
        $this->createDirectory($comicsDirectory, $io);
        $this->createDirectory($coversDirectory, $io);
        
        $io->success('Upload directories created successfully.');
        
        return Command::SUCCESS;
    }
    
    private function createDirectory(string $directory, SymfonyStyle $io): void
    {
        if (!file_exists($directory)) {
            $io->note(sprintf('Creating directory: %s', $directory));
            if (!mkdir($directory, 0777, true)) {
                $io->error(sprintf('Failed to create directory: %s', $directory));
                return;
            }
        } else {
            $io->note(sprintf('Directory already exists: %s', $directory));
        }
        
        // Ensure directory is writable
        if (!is_writable($directory)) {
            $io->note(sprintf('Setting permissions on directory: %s', $directory));
            chmod($directory, 0777);
        }
    }
}
