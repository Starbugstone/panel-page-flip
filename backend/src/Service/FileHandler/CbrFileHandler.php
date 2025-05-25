<?php

namespace App\Service\FileHandler;

/**
 * Handler for CBR (Comic Book RAR) files
 */
class CbrFileHandler extends AbstractComicFileHandler
{
    /**
     * {@inheritdoc}
     */
    public function supports(string $filePath): bool
    {
        return $this->getFileExtension($filePath) === 'cbr';
    }
    
    /**
     * {@inheritdoc}
     */
    public function countImageFiles(string $filePath): int
    {
        if (!$this->isFileAccessible($filePath)) {
            return 0;
        }
        
        // Try using RarArchive extension if available (expected on production)
        if (extension_loaded('rar') && class_exists('\\RarArchive')) {
            return $this->countImagesUsingRarExtension($filePath);
        }
        
        // Fall back to command line tools
        return $this->countImagesUsingCommandLine($filePath);
    }
    
    /**
     * Count images in a CBR file using the RAR extension
     * 
     * @param string $filePath Path to the CBR file
     * @return int Number of images in the file
     */
    private function countImagesUsingRarExtension(string $filePath): int
    {
        try {
            $rar = \RarArchive::open($filePath);
            if (!$rar) {
                error_log("Failed to open CBR file with RarArchive: {$filePath}");
                return 0;
            }
            
            $entries = $rar->getEntries();
            if (!$entries) {
                $rar->close();
                error_log("Failed to get entries from CBR file: {$filePath}");
                return 0;
            }
            
            $imageCount = 0;
            foreach ($entries as $entry) {
                if ($entry->isDirectory()) {
                    continue;
                }
                
                $filename = $entry->getName();
                $extension = $this->getFileExtension($filename);
                if ($this->isSupportedImageExtension($extension)) {
                    $imageCount++;
                }
            }
            
            $rar->close();
            return $imageCount;
        } catch (\Exception $e) {
            error_log("Error using RarArchive: " . $e->getMessage());
            return 0;
        }
    }
    
    /**
     * Count images in a CBR file using command line tools
     * 
     * @param string $filePath Path to the CBR file
     * @return int Number of images in the file
     */
    private function countImagesUsingCommandLine(string $filePath): int
    {
        // Try unrar first
        $result = $this->runCommand(['unrar', 'lb', $filePath]);
        if ($result['success']) {
            $lines = explode("\n", $result['output']);
            $imageCount = 0;
            
            foreach ($lines as $line) {
                $line = trim($line);
                if (empty($line)) continue;
                
                $extension = $this->getFileExtension($line);
                if ($this->isSupportedImageExtension($extension)) {
                    $imageCount++;
                }
            }
            
            return $imageCount;
        }
        
        // Try 7z if unrar fails
        $result = $this->runCommand(['7z', 'l', $filePath]);
        if ($result['success']) {
            $lines = explode("\n", $result['output']);
            $imageCount = 0;
            
            foreach ($lines as $line) {
                if (preg_match('/\.(jpg|jpeg|png|gif|webp)$/i', $line)) {
                    $imageCount++;
                }
            }
            
            return $imageCount;
        }
        
        error_log("Failed to count images in CBR file using command line tools: {$filePath}");
        return 0;
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
        
        // Try using RarArchive extension if available (expected on production)
        if (extension_loaded('rar') && class_exists('\\RarArchive')) {
            return $this->extractImageUsingRarExtension($filePath, $index, $outputDir);
        }
        
        // Fall back to command line tools
        return $this->extractImageUsingCommandLine($filePath, $index, $outputDir);
    }
    
    /**
     * Extract an image from a CBR file using the RAR extension
     * 
     * @param string $filePath Path to the CBR file
     * @param int $index Index of the image to extract
     * @param string $outputDir Directory to extract the image to
     * @return string|null Path to the extracted image or null on failure
     */
    private function extractImageUsingRarExtension(string $filePath, int $index, string $outputDir): ?string
    {
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
        try {
            $rar = \RarArchive::open($filePath);
            if (!$rar) {
                error_log("Failed to open CBR file with RarArchive: {$filePath}");
                return null;
            }
            
            $entries = $rar->getEntries();
            if (!$entries) {
                $rar->close();
                error_log("Failed to get entries from CBR file: {$filePath}");
                return null;
            }
            
            // Get all image files from the archive
            $imageFiles = [];
            foreach ($entries as $entry) {
                if ($entry->isDirectory()) {
                    continue;
                }
                
                $filename = $entry->getName();
                $extension = $this->getFileExtension($filename);
                if ($this->isSupportedImageExtension($extension)) {
                    $imageFiles[] = [
                        'name' => $filename,
                        'entry' => $entry
                    ];
                }
            }
            
            // Sort image files naturally
            usort($imageFiles, function($a, $b) {
                return strnatcmp($a['name'], $b['name']);
            });
            
            // Check if requested image exists
            if (!isset($imageFiles[$index])) {
                $rar->close();
                error_log("Image index out of bounds: {$index} (found " . count($imageFiles) . " images)");
                return null;
            }
            
            // Extract the image
            $targetEntry = $imageFiles[$index]['entry'];
            $targetFilename = basename($imageFiles[$index]['name']);
            $outputPath = $outputDir . '/' . $targetFilename;
            
            if (!$targetEntry->extract($outputDir)) {
                $rar->close();
                error_log("Failed to extract image from CBR file: {$filePath}");
                return null;
            }
            
            $rar->close();
            
            if (file_exists($outputPath)) {
                return $outputPath;
            }
            
            return null;
        } catch (\Exception $e) {
            error_log("Error using RarArchive for extraction: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Extract an image from a CBR file using command line tools
     * 
     * @param string $filePath Path to the CBR file
     * @param int $index Index of the image to extract
     * @param string $outputDir Directory to extract the image to
     * @return string|null Path to the extracted image or null on failure
     */
    private function extractImageUsingCommandLine(string $filePath, int $index, string $outputDir): ?string
    {
        // Security check: Validate file path to prevent command injection
        if (!preg_match('/^[\w\-\.\/\\\s]+$/', $filePath) || strpos($filePath, '..') !== false) {
            error_log("Security warning: Potentially unsafe file path: {$filePath}");
            return null;
        }
        
        // Security check: Validate output directory
        if (!preg_match('/^[\w\-\.\/\\\s]+$/', $outputDir) || strpos($outputDir, '..') !== false) {
            error_log("Security warning: Potentially unsafe output directory: {$outputDir}");
            return null;
        }
        // Try with unrar if available
        $result = $this->runCommand(['unrar', 'lb', $filePath]);
        if ($result['success']) {
            $lines = explode("\n", $result['output']);
            
            $imageFiles = [];
            foreach ($lines as $line) {
                $line = trim($line);
                if (empty($line)) continue;
                
                $extension = $this->getFileExtension($line);
                if ($this->isSupportedImageExtension($extension)) {
                    $imageFiles[] = $line;
                }
            }
            
            // Sort image files naturally
            $imageFiles = $this->sortFilesNaturally($imageFiles);
            
            if (count($imageFiles) > $index) {
                $targetImage = $imageFiles[$index];
                
                // Extract the specific image
                $extractResult = $this->runCommand(['unrar', 'e', '-o+', $filePath, $targetImage, $outputDir]);
                
                if ($extractResult['success']) {
                    $extractedPath = $outputDir . '/' . basename($targetImage);
                    if (file_exists($extractedPath)) {
                        return $extractedPath;
                    }
                }
            }
        }
        
        // Try with 7z if unrar fails
        $result = $this->runCommand(['7z', 'l', $filePath]);
        if ($result['success']) {
            $lines = explode("\n", $result['output']);
            
            $imageFiles = [];
            foreach ($lines as $line) {
                if (preg_match('/\.(jpg|jpeg|png|gif|webp)$/i', $line)) {
                    if (preg_match('/[^\s]+\.(jpg|jpeg|png|gif|webp)$/i', $line, $matches)) {
                        $imageFiles[] = $matches[0];
                    }
                }
            }
            
            // Sort image files naturally
            $imageFiles = $this->sortFilesNaturally($imageFiles);
            
            if (count($imageFiles) > $index) {
                $targetImage = $imageFiles[$index];
                
                // Extract the specific image
                $extractResult = $this->runCommand(['7z', 'e', $filePath, $targetImage, '-o' . $outputDir, '-y']);
                
                if ($extractResult['success']) {
                    $extractedPath = $outputDir . '/' . basename($targetImage);
                    if (file_exists($extractedPath)) {
                        return $extractedPath;
                    }
                }
            }
        }
        
        return null;
    }
}
