<?php

namespace App\Service;

use App\Entity\Comic;
use App\Entity\Tag;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\String\Slugger\SluggerInterface;
use ZipArchive;

/**
 * Service for handling comic-related operations
 */
class ComicService
{
    private string $comicsDirectory;
    private EntityManagerInterface $entityManager;
    private SluggerInterface $slugger;

    public function __construct(
        string $comicsDirectory,
        EntityManagerInterface $entityManager,
        SluggerInterface $slugger
    ) {
        $this->comicsDirectory = $comicsDirectory;
        $this->entityManager = $entityManager;
        $this->slugger = $slugger;
    }

    /**
     * Upload a new comic file for a user
     *
     * @param UploadedFile $file The uploaded CBZ file
     * @param User $user The user who is uploading the comic
     * @param string $title The comic title
     * @param string|null $author The comic author (optional)
     * @param string|null $publisher The comic publisher (optional)
     * @param string|null $description The comic description (optional)
     * @param array $tags Array of tag names to associate with the comic (optional)
     * @return Comic The created comic entity
     * @throws \Exception If there's an error during upload
     */
    public function uploadComic(
        UploadedFile $file,
        User $user,
        string $title,
        ?string $author = null,
        ?string $publisher = null,
        ?string $description = null,
        array $tags = []
    ): Comic {
        error_log('Starting comic upload process for user ID: ' . $user->getId());
        
        // Check if file is valid
        if (!$file->isValid()) {
            $errorMessage = 'Invalid file: ' . $file->getErrorMessage();
            error_log($errorMessage);
            throw new \Exception($errorMessage);
        }
        
        // Log file details
        error_log('File details: ' . json_encode([
            'originalName' => $file->getClientOriginalName(),
            'size' => $file->getSize(),
            'mimeType' => $file->getMimeType(),
            'error' => $file->getError()
        ]));
        
        // Validate file is a CBZ
        $originalFilename = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
        $extension = $file->getClientOriginalExtension();
        
        error_log('File extension: ' . $extension);
        
        if (empty($extension) || strtolower($extension) !== 'cbz') {
            $errorMessage = 'Only CBZ files are allowed. Got: ' . $extension;
            error_log($errorMessage);
            throw new \Exception($errorMessage);
        }

        // Create safe filename
        $safeFilename = $this->slugger->slug($originalFilename);
        $newFilename = $safeFilename . '-' . uniqid() . '.' . $extension;

        // Ensure main comics directory exists
        error_log('Checking if comics directory exists: ' . $this->comicsDirectory);
        if (!file_exists($this->comicsDirectory)) {
            error_log('Comics directory does not exist, creating it');
            if (!mkdir($this->comicsDirectory, 0777, true)) {
                $errorMsg = 'Failed to create comics directory: ' . $this->comicsDirectory;
                error_log($errorMsg);
                throw new \Exception($errorMsg);
            }
            chmod($this->comicsDirectory, 0777); // Ensure directory is writable
            error_log('Comics directory created successfully');
        }
        
        // Ensure user directory exists
        $userDirectory = $this->comicsDirectory . '/' . $user->getId();
        error_log('Checking if user directory exists: ' . $userDirectory);
        if (!file_exists($userDirectory)) {
            error_log('User directory does not exist, creating it');
            if (!mkdir($userDirectory, 0777, true)) {
                $errorMsg = 'Failed to create user directory: ' . $userDirectory;
                error_log($errorMsg);
                throw new \Exception($errorMsg);
            }
            chmod($userDirectory, 0777); // Ensure directory is writable
            error_log('User directory created successfully');
        }
        
        // Move file to user's comics directory
        try {
            error_log('Attempting to move file to: ' . $userDirectory . '/' . $newFilename);
            
            // Create a temporary copy of the file to ensure it's accessible
            $tempPath = sys_get_temp_dir() . '/' . uniqid('comic_') . '.cbz';
            error_log('Creating temporary copy at: ' . $tempPath);
            
            if (!copy($file->getPathname(), $tempPath)) {
                $errorMsg = 'Failed to create temporary copy of the file';
                error_log($errorMsg);
                throw new \Exception($errorMsg);
            }
            
            // Move the temporary file to the final destination
            if (!rename($tempPath, $userDirectory . '/' . $newFilename)) {
                $errorMsg = 'Failed to move file from temporary location to final destination';
                error_log($errorMsg);
                throw new \Exception($errorMsg);
            }
            
            // Verify the file was moved successfully
            if (!file_exists($userDirectory . '/' . $newFilename)) {
                $errorMsg = 'File was not moved successfully';
                error_log($errorMsg);
                throw new \Exception($errorMsg);
            }
            
            // Ensure the file is readable
            chmod($userDirectory . '/' . $newFilename, 0644);
            error_log('File moved successfully to: ' . $userDirectory . '/' . $newFilename);
        } catch (\Exception $e) {
            $errorMsg = 'Failed to upload file: ' . $e->getMessage();
            error_log($errorMsg);
            throw new \Exception($errorMsg);
        }
        
        // Count pages in CBZ
        $pageCount = $this->countPagesInCbz($userDirectory . '/' . $newFilename);

        // Create new comic entity
        $comic = new Comic();
        $comic->setTitle($title);
        $comic->setFilePath($newFilename); // Just the filename, e.g., "mycomic-uniqid.cbz"
        $comic->setPageCount($pageCount);
        $comic->setOwner($user);

        if ($author) {
            $comic->setAuthor($author);
        }

        if ($publisher) {
            $comic->setPublisher($publisher);
        }

        if ($description) {
            $comic->setDescription($description);
        }

        // Add tags
        if (!empty($tags)) {
            foreach ($tags as $tagName) {
                // Check if tag exists
                $tag = $this->entityManager->getRepository(Tag::class)->findOneBy(['name' => $tagName]);
                if (!$tag) {
                    // Create new tag
                    $tag = new Tag();
                    $tag->setName($tagName);
                    $tag->setCreator($user);
                    $this->entityManager->persist($tag);
                }
                $comic->addTag($tag);
            }
        }
        
        // Persist comic to get an ID
        $this->entityManager->persist($comic);
        $this->entityManager->flush(); // Flush to get the ID

        // Base filename for the cover, from original CBZ name
        $baseCoverFilename = $this->slugger->slug(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME));
        
        // Extract cover image, using the generated comic ID
        $coverImagePathRelativeToUserDir = $this->extractCoverImage(
            $userDirectory . '/' . $newFilename, // Full absolute path to the CBZ file
            $user,
            $comic->getId(),
            $baseCoverFilename
        );

        $comic->setCoverImagePath($coverImagePathRelativeToUserDir);

        // Flush again to save the cover image path
        $this->entityManager->flush();

        return $comic;
    }

    /**
     * Delete a comic and its associated files
     *
     * @param Comic $comic The comic to delete
     * @return bool True if deletion was successful
     */
    public function deleteComic(Comic $comic): bool
    {
        $user = $comic->getOwner();
        if (!$user) {
            // Or throw an exception, as a comic should always have an owner
            error_log("Comic with ID " . $comic->getId() . " has no owner. Cannot delete files properly.");
            // Depending on policy, you might still proceed to delete the entity
            // but file deletion will be compromised.
            // For this implementation, we'll assume owner is always present for file operations.
            // If not, the logic below for $user->getId() would fail.
            // Consider adding a check here if $user can truly be null for a persisted Comic.
        }

        // Delete CBZ file from filesystem
        if ($user && $comic->getFilePath()) {
            // $comic->getFilePath() stores just the filename, e.g., "mycomic-uniqid.cbz"
            // It's stored directly under $this->comicsDirectory / $user->getId() /
            $cbzFilePath = $this->comicsDirectory . '/' . $user->getId() . '/' . ltrim($comic->getFilePath(), '/');
            if (file_exists($cbzFilePath)) {
                unlink($cbzFilePath);
            }
        }

        // Delete cover image if exists, using the new path structure
        // $comic->getCoverImagePath() stores "covers/{comic_id}/actual_cover.jpg"
        if ($user && $comic->getCoverImagePath()) {
            $absoluteCoverPath = $this->comicsDirectory . '/' . $user->getId() . '/' . ltrim($comic->getCoverImagePath(), '/');
            if (file_exists($absoluteCoverPath)) {
                unlink($absoluteCoverPath);

                // Attempt to remove the comic-specific cover directory (e.g., .../covers/{comic_id}/)
                $comicCoverDir = dirname($absoluteCoverPath);
                if (is_dir($comicCoverDir)) {
                    // Check if directory is empty (contains only '.' and '..')
                    $items = scandir($comicCoverDir);
                    if (count($items) == 2) { // Only '.' and '..'
                        rmdir($comicCoverDir);
                        
                        // Optionally, try to remove the parent 'covers' directory 
                        // (e.g., public/uploads/comics/{user_id}/covers) if it's also empty.
                        // This is generally safe if 'covers' directory only ever contains comic_id subdirectories.
                        $userCoversDir = dirname($comicCoverDir); // This would be .../comics/{user_id}/covers
                        if (basename($userCoversDir) === 'covers' && is_dir($userCoversDir) && count(scandir($userCoversDir)) == 2) {
                            rmdir($userCoversDir);
                        }
                    }
                }
            }
        }

        // Delete comic from database
        $this->entityManager->remove($comic);
        $this->entityManager->flush();

        return true;
    }

    /**
     * Extract the cover image from a CBZ file
     * 
     * @param string $cbzPath Path to the CBZ file
     * @param string $baseFilename Base filename for the cover image
     * @param string $cbzAbsPath Full absolute path to the CBZ file
     * @param User $user The user object
     * @param int $comicId The ID of the comic (already persisted)
     * @param string $baseCoverFilename Base filename for the cover image (slugged original CBZ name)
     * @return string|null Path to the extracted cover image, relative to the user's comic directory.
     *                     e.g., "covers/456/mycomic-cover-qwert.jpg"
     * @throws \Exception If there's an error extracting the cover image
     */
    private function extractCoverImage(string $cbzAbsPath, User $user, int $comicId, string $baseCoverFilename): ?string
    {
        // Verify the CBZ file exists
        if (!file_exists($cbzAbsPath)) {
            error_log("CBZ file not found at path: {$cbzAbsPath}");
            throw new \Exception("CBZ file not found at path: {$cbzAbsPath}");
        }
        
        $zip = new ZipArchive();
        $openResult = $zip->open($cbzAbsPath);
        if ($openResult !== true) {
            error_log("Failed to open CBZ file {$cbzAbsPath}. Error code: {$openResult}");
            throw new \Exception("Failed to open CBZ file. Error code: {$openResult}");
        }

        // Get all image files from the archive
        $imageFiles = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $filename = $zip->getNameIndex($i);
            // Skip files in macOS specific __MACOSX directory or other hidden files
            if (strpos($filename, '__MACOSX/') === 0 || strpos($filename, '.') === 0) {
                continue;
            }
            $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
            if (in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp'])) {
                $imageFiles[] = $filename;
            }
        }

        // Sort image files naturally (1, 2, 10 instead of 1, 10, 2)
        usort($imageFiles, 'strnatcmp');

        if (empty($imageFiles)) {
            $zip->close();
            error_log("No image files found in CBZ: {$cbzAbsPath}");
            throw new \Exception("No image files found in the CBZ archive");
        }

        $firstImageNameInZip = $imageFiles[0];
        $coverExtension = strtolower(pathinfo($firstImageNameInZip, PATHINFO_EXTENSION));
        $actualCoverFilename = $baseCoverFilename . '-cover-' . uniqid() . '.' . $coverExtension;

        // Path where this specific comic's covers will be stored (absolute)
        // $this->comicsDirectory is already '%kernel.project_dir%/public/uploads/comics'
        $coverStorageDirAbs = $this->comicsDirectory . '/' . $user->getId() . '/covers/' . $comicId;

        if (!file_exists($coverStorageDirAbs)) {
            error_log("Creating cover directory: {$coverStorageDirAbs}");
            if (!mkdir($coverStorageDirAbs, 0777, true)) {
                $zip->close();
                error_log("Failed to create cover directory: {$coverStorageDirAbs}");
                throw new \Exception("Failed to create cover directory: {$coverStorageDirAbs}");
            }
            // No need to chmod 0777 on the final directory, parent directory permissions should suffice.
        }

        // Extract cover image data
        $extractedImageData = $zip->getFromName($firstImageNameInZip);
        if ($extractedImageData === false) {
            $zip->close();
            error_log("Failed to extract cover image data from {$firstImageNameInZip} in {$cbzAbsPath}");
            throw new \Exception("Failed to extract cover image data from {$firstImageNameInZip} in {$cbzAbsPath}");
        }
        
        $fullCoverPathOnDisk = $coverStorageDirAbs . '/' . $actualCoverFilename;
        error_log("Saving cover image to disk: {$fullCoverPathOnDisk}");
        if (file_put_contents($fullCoverPathOnDisk, $extractedImageData) === false) {
            $zip->close();
            error_log("Failed to save cover image to disk: {$fullCoverPathOnDisk}");
            throw new \Exception("Failed to save cover image to disk: {$fullCoverPathOnDisk}");
        }
        chmod($fullCoverPathOnDisk, 0644); // Ensure the file itself is readable
        
        $zip->close();

        // Return path relative to the user's main comic directory (e.g., "userId/covers/comicId/filename.jpg")
        // The Comic->getCoverImagePath() should store "covers/comicId/filename.jpg"
        // And the ComicController will prepend "userId/" to it.
        // OR, more simply, the ComicController will prepend the *full base URL* up to "userId/"
        // and this method returns the path *relative to the user's comic directory*.
        return 'covers/' . $comicId . '/' . $actualCoverFilename;
    }

    /**
     * Count the number of pages in a CBZ file
     * 
     * TODO: Improve page counting accuracy when implementing a proper CBZ tool.
     * Current implementation may not correctly handle nested directories or
     * non-image files that might be included in the CBZ archive.
     * 
     * @param string $cbzPath Path to the CBZ file
     * @return int Number of pages (images) in the CBZ file
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
