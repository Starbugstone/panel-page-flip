<?php

namespace App\Controller;

use App\Entity\Comic;
use App\Entity\ComicReadingProgress;
use App\Entity\Tag;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\String\Slugger\SluggerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use ZipArchive;

#[Route('/api/comics', name: 'api_comics_')]
class ComicController extends AbstractController
{
    #[Route('', name: 'list', methods: ['GET'])]
    public function list(EntityManagerInterface $entityManager): JsonResponse
    {
        // Get the current user
        $user = $this->getUser();
        if (!$user) {
            return $this->json(['message' => 'User not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        // Get comics for the current user
        $comics = $entityManager->getRepository(Comic::class)->findBy(['owner' => $user]);

        // Transform comics to array
        $comicsArray = [];
        foreach ($comics as $comic) {
            $comicsArray[] = [
                'id' => $comic->getId(),
                'title' => $comic->getTitle(),
                'author' => $comic->getAuthor(),
                'publisher' => $comic->getPublisher(),
                'description' => $comic->getDescription(),
                'coverImagePath' => $comic->getCoverImagePath(),
                'pageCount' => $comic->getPageCount(),
                'uploadedAt' => $comic->getUploadedAt()->format('c'),
                'tags' => array_map(function ($tag) {
                    return [
                        'id' => $tag->getId(),
                        'name' => $tag->getName()
                    ];
                }, $comic->getTags()->toArray())
            ];
        }

        return $this->json(['comics' => $comicsArray]);
    }

    #[Route('/{id}', name: 'get', methods: ['GET'])]
    public function get(int $id, EntityManagerInterface $entityManager): JsonResponse
    {
        // Get the current user
        $user = $this->getUser();
        if (!$user) {
            return $this->json(['message' => 'User not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        // Get comic by id and owner
        $comic = $entityManager->getRepository(Comic::class)->findOneBy(['id' => $id, 'owner' => $user]);
        if (!$comic) {
            return $this->json(['message' => 'Comic not found'], Response::HTTP_NOT_FOUND);
        }

        // Get reading progress if exists
        $readingProgress = $entityManager->getRepository(ComicReadingProgress::class)
            ->findOneBy(['comic' => $comic, 'user' => $user]);

        // Transform comic to array
        $comicArray = [
            'id' => $comic->getId(),
            'title' => $comic->getTitle(),
            'author' => $comic->getAuthor(),
            'publisher' => $comic->getPublisher(),
            'description' => $comic->getDescription(),
            'coverImagePath' => $comic->getCoverImagePath(),
            'pageCount' => $comic->getPageCount(),
            'uploadedAt' => $comic->getUploadedAt()->format('c'),
            'tags' => array_map(function ($tag) {
                return [
                    'id' => $tag->getId(),
                    'name' => $tag->getName()
                ];
            }, $comic->getTags()->toArray()),
            'readingProgress' => $readingProgress ? [
                'currentPage' => $readingProgress->getCurrentPage(),
                'lastReadAt' => $readingProgress->getLastReadAt()->format('c'),
                'completed' => $readingProgress->isCompleted()
            ] : null
        ];

        return $this->json(['comic' => $comicArray]);
    }

    #[Route('', name: 'create', methods: ['POST'])]
    public function create(
        Request $request, 
        EntityManagerInterface $entityManager, 
        ValidatorInterface $validator,
        SluggerInterface $slugger
    ): JsonResponse {
        // Get the current user
        $user = $this->getUser();
        if (!$user) {
            return $this->json(['message' => 'User not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        // Get uploaded file
        $comicFile = $request->files->get('file');
        if (!$comicFile) {
            return $this->json(['message' => 'No file uploaded'], Response::HTTP_BAD_REQUEST);
        }

        // Validate file is a CBZ
        $originalFilename = pathinfo($comicFile->getClientOriginalName(), PATHINFO_FILENAME);
        $extension = $comicFile->getClientOriginalExtension();
        if ($extension !== 'cbz') {
            return $this->json(['message' => 'Only CBZ files are allowed'], Response::HTTP_BAD_REQUEST);
        }

        // Create safe filename
        $safeFilename = $slugger->slug($originalFilename);
        $newFilename = $safeFilename . '-' . uniqid() . '.' . $extension;

        // Get form data
        $title = $request->request->get('title');
        $author = $request->request->get('author');
        $publisher = $request->request->get('publisher');
        $description = $request->request->get('description');
        $tagsString = $request->request->get('tags');
        $tags = $tagsString ? json_decode($tagsString, true) : [];

        // Validate title
        if (!$title) {
            return $this->json(['message' => 'Title is required'], Response::HTTP_BAD_REQUEST);
        }

        try {
            // Create user directory if it doesn't exist
            $comicsDirectory = $this->getParameter('comics_directory');
            $userDirectory = $comicsDirectory . '/' . $user->getId();
            if (!file_exists($userDirectory)) {
                mkdir($userDirectory, 0777, true);
            }
            
            // Move file to user's comics directory
            $comicFile->move($userDirectory, $newFilename);
            
            // Extract cover image from CBZ
            $coverImagePath = $this->extractCoverImage($userDirectory . '/' . $newFilename, $safeFilename, $comicsDirectory);

            // Count pages in CBZ
            $pageCount = $this->countPagesInCbz($userDirectory . '/' . $newFilename);

            // Create new comic
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
                    $tag = $entityManager->getRepository(Tag::class)->findOneBy(['name' => $tagName]);
                    if (!$tag) {
                        // Create new tag
                        $tag = new Tag();
                        $tag->setName($tagName);
                        $tag->setCreator($user);
                        $entityManager->persist($tag);
                    }
                    $comic->addTag($tag);
                }
            }

            // Save comic
            $entityManager->persist($comic);
            $entityManager->flush();

            return $this->json([
                'message' => 'Comic uploaded successfully',
                'comic' => [
                    'id' => $comic->getId(),
                    'title' => $comic->getTitle()
                ]
            ], Response::HTTP_CREATED);

        } catch (FileException $e) {
            return $this->json(['message' => 'Failed to upload file: ' . $e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    #[Route('/{id}', name: 'update', methods: ['PUT', 'PATCH'])]
    public function update(
        int $id, 
        Request $request, 
        EntityManagerInterface $entityManager,
        ValidatorInterface $validator
    ): JsonResponse {
        // Get the current user
        $user = $this->getUser();
        if (!$user) {
            return $this->json(['message' => 'User not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        // Get comic by id and owner
        $comic = $entityManager->getRepository(Comic::class)->findOneBy(['id' => $id, 'owner' => $user]);
        if (!$comic) {
            return $this->json(['message' => 'Comic not found'], Response::HTTP_NOT_FOUND);
        }

        // Get data from request
        $data = json_decode($request->getContent(), true);
        if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
            return $this->json(['message' => 'Invalid JSON payload'], Response::HTTP_BAD_REQUEST);
        }

        // Update comic properties
        if (isset($data['title'])) {
            $comic->setTitle($data['title']);
        }

        if (isset($data['author'])) {
            $comic->setAuthor($data['author']);
        }

        if (isset($data['publisher'])) {
            $comic->setPublisher($data['publisher']);
        }

        if (isset($data['description'])) {
            $comic->setDescription($data['description']);
        }

        // Update tags if provided
        if (isset($data['tags']) && is_array($data['tags'])) {
            // Remove all existing tags
            foreach ($comic->getTags() as $tag) {
                $comic->removeTag($tag);
            }

            // Add new tags
            foreach ($data['tags'] as $tagName) {
                // Check if tag exists
                $tag = $entityManager->getRepository(Tag::class)->findOneBy(['name' => $tagName]);
                if (!$tag) {
                    // Create new tag
                    $tag = new Tag();
                    $tag->setName($tagName);
                    $tag->setCreator($user);
                    $entityManager->persist($tag);
                }
                $comic->addTag($tag);
            }
        }

        // Save changes
        $entityManager->flush();

        return $this->json([
            'message' => 'Comic updated successfully',
            'comic' => [
                'id' => $comic->getId(),
                'title' => $comic->getTitle()
            ]
        ]);
    }

    #[Route('/{id}', name: 'delete', methods: ['DELETE'])]
    public function delete(int $id, EntityManagerInterface $entityManager): JsonResponse
    {
        // Get the current user
        $user = $this->getUser();
        if (!$user) {
            return $this->json(['message' => 'User not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        // Get comic by id and owner
        $comic = $entityManager->getRepository(Comic::class)->findOneBy(['id' => $id, 'owner' => $user]);
        if (!$comic) {
            return $this->json(['message' => 'Comic not found'], Response::HTTP_NOT_FOUND);
        }

        // Delete file from filesystem
        $comicsDirectory = $this->getParameter('comics_directory');
        $userDirectory = $comicsDirectory . '/' . $comic->getOwner()->getId();
        $filePath = $userDirectory . '/' . $comic->getFilePath();
        
        // Try user directory first, then fallback to old path
        if (!file_exists($filePath)) {
            $filePath = $comicsDirectory . '/' . $comic->getFilePath();
        }
        
        if (file_exists($filePath)) {
            unlink($filePath);
        }

        // Delete cover image if exists
        if ($comic->getCoverImagePath()) {
            // Try both possible cover paths
            $coverPath = $comicsDirectory . '/' . $comic->getCoverImagePath();
            if (file_exists($coverPath)) {
                unlink($coverPath);
            }
            
            // Also check if there's a cover in a comic-specific directory
            $comicIdPattern = '/\/covers\/([0-9]+)\//';
            $coverPathWithoutComicId = preg_replace($comicIdPattern, '/covers/', $comic->getCoverImagePath());
            $coverPathWithComicId = 'covers/' . $comic->getId() . '/' . basename($comic->getCoverImagePath());
            
            $alternateCoverPath = $comicsDirectory . '/' . $coverPathWithComicId;
            if (file_exists($alternateCoverPath)) {
                unlink($alternateCoverPath);
            }
        }

        // Delete comic from database
        $entityManager->remove($comic);
        $entityManager->flush();

        return $this->json(['message' => 'Comic deleted successfully']);
    }

    #[Route('/{id}/pages/{page}', name: 'get_page', methods: ['GET'])]
    public function getPage(int $id, int $page, EntityManagerInterface $entityManager): Response
    {
        // Get the current user
        $user = $this->getUser();
        if (!$user) {
            return $this->json(['message' => 'User not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        // Get comic by id and owner
        $comic = $entityManager->getRepository(Comic::class)->findOneBy(['id' => $id, 'owner' => $user]);
        if (!$comic) {
            return $this->json(['message' => 'Comic not found'], Response::HTTP_NOT_FOUND);
        }

        // Validate page number
        if ($page < 1 || ($comic->getPageCount() !== null && $page > $comic->getPageCount())) {
            return $this->json(['message' => 'Invalid page number'], Response::HTTP_BAD_REQUEST);
        }

        // Get comic file from user's directory
        $comicsDirectory = $this->getParameter('comics_directory');
        $userDirectory = $comicsDirectory . '/' . $comic->getOwner()->getId();
        $filePath = $userDirectory . '/' . $comic->getFilePath();
        
        // Fallback to old path if file doesn't exist in user directory
        if (!file_exists($filePath)) {
            $filePath = $comicsDirectory . '/' . $comic->getFilePath();
            
            // If still not found, return error
            if (!file_exists($filePath)) {
                return $this->json(['message' => 'Comic file not found'], Response::HTTP_NOT_FOUND);
            }
        }

        // Open CBZ file
        $zip = new ZipArchive();
        if ($zip->open($filePath) !== true) {
            return $this->json(['message' => 'Failed to open comic file'], Response::HTTP_INTERNAL_SERVER_ERROR);
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

        // Check if requested page exists
        if (!isset($imageFiles[$page - 1])) {
            $zip->close();
            return $this->json(['message' => 'Page not found'], Response::HTTP_NOT_FOUND);
        }

        // Get page image
        $pageImage = $zip->getFromName($imageFiles[$page - 1]);
        $zip->close();

        if ($pageImage === false) {
            return $this->json(['message' => 'Failed to extract page image'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        // Update reading progress
        $this->updateReadingProgress($user, $comic, $page, $entityManager);

        // Return image
        $response = new Response($pageImage);
        $extension = strtolower(pathinfo($imageFiles[$page - 1], PATHINFO_EXTENSION));
        $mimeType = $this->getMimeTypeForExtension($extension);
        $response->headers->set('Content-Type', $mimeType);
        return $response;
    }

    #[Route('/{id}/progress', name: 'update_progress', methods: ['POST'])]
    public function updateReadingProgressEndpoint(
        int $id, 
        Request $request, 
        EntityManagerInterface $entityManager
    ): JsonResponse {
        // Get the current user
        $user = $this->getUser();
        if (!$user) {
            return $this->json(['message' => 'User not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        // Get comic by id and owner
        $comic = $entityManager->getRepository(Comic::class)->findOneBy(['id' => $id, 'owner' => $user]);
        if (!$comic) {
            return $this->json(['message' => 'Comic not found'], Response::HTTP_NOT_FOUND);
        }

        // Get data from request
        $data = json_decode($request->getContent(), true);
        if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
            return $this->json(['message' => 'Invalid JSON payload'], Response::HTTP_BAD_REQUEST);
        }

        // Validate page number
        if (!isset($data['currentPage']) || !is_numeric($data['currentPage']) || $data['currentPage'] < 1) {
            return $this->json(['message' => 'Valid currentPage is required'], Response::HTTP_BAD_REQUEST);
        }

        $currentPage = (int) $data['currentPage'];
        $completed = isset($data['completed']) ? (bool) $data['completed'] : false;

        // Update reading progress
        $progress = $this->updateReadingProgress($user, $comic, $currentPage, $entityManager, $completed);

        return $this->json([
            'message' => 'Reading progress updated',
            'progress' => [
                'currentPage' => $progress->getCurrentPage(),
                'lastReadAt' => $progress->getLastReadAt()->format('c'),
                'completed' => $progress->isCompleted()
            ]
        ]);
    }

    /**
     * Extract the cover image from a CBZ file
     * 
     * @param string $cbzPath Path to the CBZ file
     * @param string $baseFilename Base filename for the cover image
     * @param string $outputDir Base output directory
     * @param int|null $comicId Optional comic ID for organizing covers
     * @return string|null Path to the extracted cover image, relative to the output directory
     */
    private function extractCoverImage(string $cbzPath, string $baseFilename, string $outputDir, ?int $comicId = null): ?string
    {
        $zip = new ZipArchive();
        if ($zip->open($cbzPath) !== true) {
            return null;
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
            return null;
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
            mkdir($coverPath, 0777, true);
        }

        // Extract cover image
        $coverData = $zip->getFromName($coverImage);
        file_put_contents($coverPath . '/' . $coverFilename, $coverData);
        $zip->close();

        // TODO: Implement a proper CBZ reader to extract and process the first page as cover
        // This would involve parsing the CBZ file format and extracting the first page
        // For now, we're just using the first image file found in the archive

        return $coverSubDir . '/' . $coverFilename;
    }

    /**
     * Count the number of pages in a CBZ file
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

    /**
     * Update reading progress for a user and comic
     */
    private function updateReadingProgress(
        User $user, 
        Comic $comic, 
        int $currentPage, 
        EntityManagerInterface $entityManager,
        bool $completed = false
    ): ComicReadingProgress {
        // Get existing progress or create new one
        $progress = $entityManager->getRepository(ComicReadingProgress::class)
            ->findOneBy(['comic' => $comic, 'user' => $user]);

        if (!$progress) {
            $progress = new ComicReadingProgress();
            $progress->setUser($user);
            $progress->setComic($comic);
            $entityManager->persist($progress);
        }

        // Update progress
        $progress->setCurrentPage($currentPage);
        
        // Mark as completed if specified or if on the last page
        if ($completed || ($comic->getPageCount() !== null && $currentPage >= $comic->getPageCount())) {
            $progress->setCompleted(true);
        }

        $entityManager->flush();
        return $progress;
    }

    /**
     * Get MIME type for file extension
     */
    private function getMimeTypeForExtension(string $extension): string
    {
        $mimeTypes = [
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp'
        ];

        return $mimeTypes[$extension] ?? 'application/octet-stream';
    }
}
