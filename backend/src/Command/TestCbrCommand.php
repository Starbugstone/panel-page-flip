<?php

namespace App\Command;

use App\Service\FileHandler\CbrFileHandler;
use App\Service\ComicService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * A command to test CBR file handling
 * 
 * This command is used to test the CBR file handling functionality
 * 
 * Usage:
 * php bin/console app:test-cbr
 * 
 * Docker usage:
 * docker exec cbz_reader_php php bin/console app:test-cbr
 */
#[AsCommand(
    name: 'app:test-cbr',
    description: 'Test CBR file handling',
)]
class TestCbrCommand extends Command
{
    private $cbrFileHandler;
    private $comicService;

    public function __construct(
        CbrFileHandler $cbrFileHandler,
        ComicService $comicService
    ) {
        parent::__construct();
        $this->cbrFileHandler = $cbrFileHandler;
        $this->comicService = $comicService;
    }

    protected function configure(): void
    {
        $this
            ->addOption('file', 'f', InputOption::VALUE_OPTIONAL, 'Specific CBR file to test');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Testing CBR File Handling');

        // Find CBR files
        $io->section('Looking for CBR files');
        $comicsDir = '/var/www/html/public/uploads/comics';
        $command = "find $comicsDir -name '*.cbr'";
        exec($command, $files, $returnCode);

        if ($returnCode !== 0 || empty($files)) {
            $io->error('No CBR files found or error running find command');
            return Command::FAILURE;
        }

        $io->success(sprintf('Found %d CBR files', count($files)));
        foreach ($files as $file) {
            $io->text("- $file");
        }

        // Test specific file or first found
        $specificFile = $input->getOption('file');
        $testFile = $specificFile ?: $files[0];
        
        $io->section(sprintf('Testing with file: %s', $testFile));

        // Test if file exists
        if (!file_exists($testFile)) {
            $io->error('File does not exist');
            return Command::FAILURE;
        }
        $io->success('File exists');

        // Test if 7z is available
        $io->section('Testing if 7z is available');
        exec('which 7z', $output7z, $returnCode7z);
        if ($returnCode7z === 0 && !empty($output7z)) {
            $io->success(sprintf('7z found: %s', $output7z[0]));
        } else {
            $io->warning('7z not found');
        }

        // Test direct 7z command
        $io->section('Testing direct 7z command');
        $command7z = "7z l \"$testFile\"";
        $io->text("Running command: $command7z");
        exec($command7z, $output7zList, $returnCode7zList);
        
        if ($returnCode7zList === 0) {
            $io->success('7z listing successful');
            $io->text(sprintf('Output length: %d lines', count($output7zList)));
            
            // Count image files
            $imageCount = 0;
            foreach ($output7zList as $line) {
                if (preg_match('/\.(jpg|jpeg|png|gif|webp)$/i', $line)) {
                    $imageCount++;
                }
            }
            
            $io->success(sprintf('Found %d image files in the CBR archive', $imageCount));
            
            // Show some of the image filenames
            $io->section('Sample image filenames');
            $imageFiles = [];
            foreach ($output7zList as $line) {
                if (preg_match('/\.(jpg|jpeg|png|gif|webp)$/i', $line)) {
                    $matches = [];
                    preg_match('/[^\s]+\.(jpg|jpeg|png|gif|webp)$/i', $line, $matches);
                    if (!empty($matches[0])) {
                        $imageFiles[] = $matches[0];
                    }
                }
            }
            
            // Sort image files naturally
            usort($imageFiles, 'strnatcmp');
            
            // Show up to 5 image filenames
            $sampleCount = min(5, count($imageFiles));
            for ($i = 0; $i < $sampleCount; $i++) {
                $io->text("- " . $imageFiles[$i]);
            }
        } else {
            $io->error('7z listing failed');
            $io->text(sprintf('Return code: %d', $returnCode7zList));
            if (!empty($output7zList)) {
                $io->text('Output: ' . implode("\n", $output7zList));
            }
        }

        // Test CbrFileHandler
        $io->section('Testing CbrFileHandler');
        try {
            $count = $this->cbrFileHandler->countImages($testFile);
            $io->success(sprintf('CbrFileHandler found %d images', $count));
        } catch (\Exception $e) {
            $io->error('CbrFileHandler failed: ' . $e->getMessage());
        }

        // Test ComicService
        $io->section('Testing ComicService');
        try {
            $count = $this->comicService->countPages($testFile);
            $io->success(sprintf('ComicService found %d pages', $count));
        } catch (\Exception $e) {
            $io->error('ComicService failed: ' . $e->getMessage());
        }

        return Command::SUCCESS;
    }
}
