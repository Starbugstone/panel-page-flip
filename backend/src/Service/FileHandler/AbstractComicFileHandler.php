<?php

namespace App\Service\FileHandler;

use App\Service\FileHandler\Interface\ComicFileHandlerInterface;
use Symfony\Component\Process\Process;

/**
 * Abstract base class for comic file handlers
 * 
 * This class provides common functionality for all comic file handlers
 */
abstract class AbstractComicFileHandler implements ComicFileHandlerInterface
{
    /**
     * List of supported image extensions
     * 
     * @var array
     */
    protected array $supportedImageExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    
    /**
     * Check if the file exists and is readable
     * 
     * @param string $filePath Path to the file
     * @return bool True if the file exists and is readable
     */
    protected function isFileAccessible(string $filePath): bool
    {
        if (!file_exists($filePath) || !is_readable($filePath)) {
            error_log("Comic file not found or not readable: {$filePath}");
            return false;
        }
        return true;
    }
    
    /**
     * Ensure the output directory exists
     * 
     * @param string $outputDir Directory to check/create
     * @return bool True if the directory exists or was created successfully
     */
    protected function ensureOutputDirectoryExists(string $outputDir): bool
    {
        if (!file_exists($outputDir)) {
            if (!mkdir($outputDir, 0777, true)) {
                error_log("Failed to create output directory: {$outputDir}");
                return false;
            }
        }
        return true;
    }
    
    /**
     * Check if the given extension is a supported image extension
     * 
     * @param string $extension File extension (without the dot)
     * @return bool True if the extension is supported
     */
    protected function isSupportedImageExtension(string $extension): bool
    {
        return in_array(strtolower($extension), $this->supportedImageExtensions);
    }
    
    /**
     * Sort an array of filenames naturally (1, 2, 10 instead of 1, 10, 2)
     * 
     * @param array $files Array of filenames to sort
     * @return array Sorted array of filenames
     */
    protected function sortFilesNaturally(array $files): array
    {
        usort($files, 'strnatcmp');
        return $files;
    }
    
    /**
     * Run a command and get its output
     * 
     * @param array $command Command to run
     * @return array [success, output, error]
     */
    protected function runCommand(array $command): array
    {
        $process = new Process($command);
        $process->run();
        
        return [
            'success' => $process->isSuccessful(),
            'output' => $process->getOutput(),
            'error' => $process->getErrorOutput()
        ];
    }
    
    /**
     * Get the file extension from a path
     * 
     * @param string $filePath Path to the file
     * @return string File extension (lowercase, without the dot)
     */
    protected function getFileExtension(string $filePath): string
    {
        return strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
    }
}
