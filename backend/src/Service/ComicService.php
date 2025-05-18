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
        
        // Extract cover image from CBZ
        $coverImagePath = $this->extractCoverImage(
            $userDirectory . '/' . $newFilename, 
            $safeFilename, 
            $this->comicsDirectory
        );

        // Count pages in CBZ
        $pageCount = $this->countPagesInCbz($userDirectory . '/' . $newFilename);

        // Create new comic entity
        $comic = new Comic();
        $comic->setTitle($title);
        $comic->setFilePath($newFilename);
        $comic->setCoverImagePath($coverImagePath);
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

        // Save comic
        $this->entityManager->persist($comic);
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
        // Delete file from filesystem
        $userDirectory = $this->comicsDirectory . '/' . $comic->getOwner()->getId();
        $filePath = $userDirectory . '/' . $comic->getFilePath();
        
        // Try user directory first, then fallback to old path
        if (!file_exists($filePath)) {
            $filePath = $this->comicsDirectory . '/' . $comic->getFilePath();
        }
        
        if (file_exists($filePath)) {
            unlink($filePath);
        }

        // Delete cover image if exists
        if ($comic->getCoverImagePath()) {
            // Try both possible cover paths
            $coverPath = $this->comicsDirectory . '/' . $comic->getCoverImagePath();
            if (file_exists($coverPath)) {
                unlink($coverPath);
            }
            
            // Also check if there's a cover in a comic-specific directory
            $comicIdPattern = '/\/covers\/([0-9]+)\//';
            $coverPathWithoutComicId = preg_replace($comicIdPattern, '/covers/', $comic->getCoverImagePath());
            $coverPathWithComicId = 'covers/' . $comic->getId() . '/' . basename($comic->getCoverImagePath());
            
            $alternateCoverPath = $this->comicsDirectory . '/' . $coverPathWithComicId;
            if (file_exists($alternateCoverPath)) {
                unlink($alternateCoverPath);
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
     * @param string $outputDir Base output directory
     * @param int|null $comicId Optional comic ID for organizing covers
     * @return string|null Path to the extracted cover image, relative to the output directory
     * @throws \Exception If there's an error extracting the cover image
     */
    private function extractCoverImage(string $cbzPath, string $baseFilename, string $outputDir, ?int $comicId = null): ?string
    {
        // Verify the CBZ file exists
        if (!file_exists($cbzPath)) {
            throw new \Exception("CBZ file not found at path: {$cbzPath}");
        }
        
        $zip = new ZipArchive();
        $openResult = $zip->open($cbzPath);
        if ($openResult !== true) {
            throw new \Exception("Failed to open CBZ file. Error code: {$openResult}");
        }

        // Get all image files from the archive
        $imageFiles = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $filename = $zip->getNameIndex($i);
            $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
            if (in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp'])) {
                $imageFiles[] = $filename;
            }
        }

        // Sort image files naturally (1, 2, 10 instead of 1, 10, 2)
        usort($imageFiles, 'strnatcmp');

        // Use the first image as cover
        if (empty($imageFiles)) {
            $zip->close();
            throw new \Exception("No image files found in the CBZ archive");
        }

        $coverImage = $imageFiles[0];
        $coverExtension = strtolower(pathinfo($coverImage, PATHINFO_EXTENSION));
        
        // Organize covers by comic ID if available
        $coverSubDir = 'covers';
        if ($comicId !== null) {
            $coverSubDir .= '/' . $comicId;
        }
        
        $coverPath = $outputDir . '/' . $coverSubDir;
        $coverFilename = $baseFilename . '-cover-' . uniqid() . '.' . $coverExtension;

        // Create covers directory if it doesn't exist
        if (!file_exists($coverPath)) {
            if (!mkdir($coverPath, 0777, true)) {
                $zip->close();
                throw new \Exception("Failed to create covers directory: {$coverPath}");
            }
            chmod($coverPath, 0777); // Ensure directory is writable
        }

        // Extract cover image
        $coverData = $zip->getFromName($coverImage);
        if ($coverData === false) {
            $zip->close();
            throw new \Exception("Failed to extract cover image from CBZ file");
        }
        
        $coverFullPath = $coverPath . '/' . $coverFilename;
        if (file_put_contents($coverFullPath, $coverData) === false) {
            $zip->close();
            throw new \Exception("Failed to save cover image to: {$coverFullPath}");
        }
        
        // Ensure the cover image is readable
        chmod($coverFullPath, 0644);
        
        $zip->close();

        return $coverSubDir . '/' . $coverFilename;
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
