<?php

namespace App\Service\FileHandler\Interface;

/**
 * Interface for comic file handlers
 * 
 * This interface defines the methods that all comic file handlers must implement
 * to ensure consistent behavior across different file types.
 */
interface ComicFileHandlerInterface
{
    /**
     * Count the number of image files in the comic archive
     * 
     * @param string $filePath Path to the comic file
     * @return int Number of image files in the archive
     */
    public function countImageFiles(string $filePath): int;
    
    /**
     * Extract a specific image from the comic file
     * 
     * @param string $filePath Path to the comic file
     * @param int $index Index of the image to extract (0-based)
     * @param string $outputDir Directory to extract the image to
     * @return string|null Path to the extracted image or null on failure
     */
    public function extractImage(string $filePath, int $index, string $outputDir): ?string;
    
    /**
     * Check if this handler supports the given file type
     * 
     * @param string $filePath Path to the comic file
     * @return bool True if this handler supports the file, false otherwise
     */
    public function supports(string $filePath): bool;
}
