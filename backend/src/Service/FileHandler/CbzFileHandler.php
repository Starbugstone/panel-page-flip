<?php

namespace App\Service\FileHandler;

use ZipArchive;

/**
 * Handler for CBZ (Comic Book ZIP) files
 */
class CbzFileHandler extends AbstractComicFileHandler
{
    /**
     * {@inheritdoc}
     */
    public function supports(string $filePath): bool
    {
        return $this->getFileExtension($filePath) === 'cbz';
    }
    
    /**
     * {@inheritdoc}
     */
    public function countImageFiles(string $filePath): int
    {
        if (!$this->isFileAccessible($filePath)) {
            return 0;
        }
        
        $zip = new ZipArchive();
        if ($zip->open($filePath) !== true) {
            error_log("Failed to open CBZ file: {$filePath}");
            return 0;
        }

        // Count image files
        $pageCount = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $filename = $zip->getNameIndex($i);
            // Skip files in macOS specific __MACOSX directory or other hidden files
            if (strpos($filename, '__MACOSX/') === 0 || strpos($filename, '.') === 0) {
                continue;
            }
            $extension = $this->getFileExtension($filename);
            if ($this->isSupportedImageExtension($extension)) {
                $pageCount++;
            }
        }

        $zip->close();
        return $pageCount;
    }
    
    /**
     * {@inheritdoc}
     */
    public function extractImage(string $filePath, int $index, string $outputDir): ?string
    {
        if (!$this->isFileAccessible($filePath)) {
            return null;
        }
        
        if (!$this->ensureOutputDirectoryExists($outputDir)) {
            return null;
        }
        
        // Security check: Validate file path to prevent path traversal
        if (strpos($filePath, '..') !== false) {
            error_log("Security warning: Path traversal attempt detected in file path: {$filePath}");
            return null;
        }
        
        // Security check: Validate output directory
        if (strpos($outputDir, '..') !== false) {
            error_log("Security warning: Path traversal attempt detected in output directory: {$outputDir}");
            return null;
        }
        
        $zip = new ZipArchive();
        $openResult = $zip->open($filePath);
        if ($openResult !== true) {
            error_log("Failed to open CBZ file {$filePath}. Error code: {$openResult}");
            return null;
        }

        // Get all image files from the archive
        $imageFiles = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $filename = $zip->getNameIndex($i);
            // Skip files in macOS specific __MACOSX directory or other hidden files
            if (strpos($filename, '__MACOSX/') === 0 || strpos($filename, '.') === 0) {
                continue;
            }
            $extension = $this->getFileExtension($filename);
            if ($this->isSupportedImageExtension($extension)) {
                $imageFiles[] = $filename;
            }
        }

        // Sort image files naturally
        $imageFiles = $this->sortFilesNaturally($imageFiles);

        if (empty($imageFiles) || !isset($imageFiles[$index])) {
            $zip->close();
            error_log("No image found at index {$index} in CBZ: {$filePath}");
            return null;
        }

        $targetImage = $imageFiles[$index];
        
        // Extract the image
        $extractedImageData = $zip->getFromName($targetImage);
        if ($extractedImageData === false) {
            $zip->close();
            error_log("Failed to extract image data from {$targetImage} in {$filePath}");
            return null;
        }
        
        $outputPath = $outputDir . '/' . basename($targetImage);
        if (file_put_contents($outputPath, $extractedImageData) === false) {
            $zip->close();
            error_log("Failed to save image to disk: {$outputPath}");
            return null;
        }
        
        chmod($outputPath, 0644); // Ensure the file is readable
        $zip->close();
        
        return $outputPath;
    }
}
