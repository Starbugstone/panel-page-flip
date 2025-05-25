<?php

namespace App\Service;

/**
 * Service for handling file security checks
 * 
 * This service provides methods to validate file paths and prevent security issues
 * like path traversal attacks.
 */
class FileSecurityService
{
    /**
     * Validate a file path to prevent path traversal attacks
     * 
     * @param string $filePath The file path to validate
     * @return bool True if the path is safe, false otherwise
     */
    public function isPathSafe(string $filePath): bool
    {
        // Check for path traversal attempts
        if (strpos($filePath, '..') !== false) {
            return false;
        }
        
        // Check for suspicious characters in the path
        if (!preg_match('/^[\w\-\.\/\\\s]+$/', $filePath)) {
            return false;
        }
        
        return true;
    }
    
    /**
     * Validate that a file is within a specific directory
     * 
     * @param string $filePath The file path to validate
     * @param string $baseDirectory The base directory the file should be in
     * @return bool True if the file is within the base directory, false otherwise
     */
    public function isFileWithinDirectory(string $filePath, string $baseDirectory): bool
    {
        // Normalize paths to avoid issues with different path formats
        $realFilePath = realpath($filePath);
        $realBaseDirectory = realpath($baseDirectory);
        
        // If either path doesn't exist, return false
        if (!$realFilePath || !$realBaseDirectory) {
            return false;
        }
        
        // Check if the file path starts with the base directory
        return strpos($realFilePath, $realBaseDirectory) === 0;
    }
    
    /**
     * Validate a directory path and ensure it exists
     * 
     * @param string $directoryPath The directory path to validate
     * @param bool $createIfNotExists Whether to create the directory if it doesn't exist
     * @param int $permissions Permissions to use if creating the directory
     * @return bool True if the directory is valid and exists, false otherwise
     */
    public function validateDirectory(string $directoryPath, bool $createIfNotExists = false, int $permissions = 0777): bool
    {
        // Check if the path is safe
        if (!$this->isPathSafe($directoryPath)) {
            return false;
        }
        
        // Check if the directory exists
        if (!file_exists($directoryPath)) {
            // Create the directory if requested
            if ($createIfNotExists) {
                return mkdir($directoryPath, $permissions, true);
            }
            return false;
        }
        
        // Check if it's actually a directory
        return is_dir($directoryPath);
    }
    
    /**
     * Get a safe file path by removing any unsafe characters
     * 
     * @param string $filePath The file path to sanitize
     * @return string The sanitized file path
     */
    public function sanitizeFilePath(string $filePath): string
    {
        // Remove path traversal sequences
        $sanitized = str_replace(['..', './'], '', $filePath);
        
        // Remove any character that's not alphanumeric, dash, underscore, dot, slash, or backslash
        $sanitized = preg_replace('/[^\w\-\.\/\\\s]/', '', $sanitized);
        
        return $sanitized;
    }
}
