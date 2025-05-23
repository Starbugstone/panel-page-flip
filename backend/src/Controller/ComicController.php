<?php

namespace App\Controller;

use App\Entity\Comic;
use App\Entity\ComicReadingProgress;
use App\Entity\Tag;
use App\Entity\User;
use App\Service\ComicService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\String\Slugger\SluggerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use ZipArchive;

#[Route('/api/comics', name: 'api_comics_')]
class ComicController extends AbstractController
{
    private string $tempUploadDir;
    private RequestStack $requestStack;
    private UrlGeneratorInterface $urlGenerator;
    
    public function __construct(
        private string $comicsDirectory,
        RequestStack $requestStack,
        UrlGeneratorInterface $urlGenerator
    ) {
        $this->tempUploadDir = sys_get_temp_dir() . '/comic_uploads';
        $this->requestStack = $requestStack;
        $this->urlGenerator = $urlGenerator;
        
        // Ensure temp directory exists
        if (!file_exists($this->tempUploadDir)) {
            mkdir($this->tempUploadDir, 0777, true);
        }
    }

    // Removed getPublicBaseUrlForUploads() method as it's no longer needed.

    #[Route('', name: 'list', methods: ['GET'])]
    public function list(EntityManagerInterface $entityManager): JsonResponse
    {
        // Get the current user
        $user = $this->getUser();
        if (!$user) {
            return $this->json(['message' => 'User not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        $comicRepository = $entityManager->getRepository(Comic::class);
        if (in_array('ROLE_ADMIN', $user->getRoles())) {
            // Admin: Get all comics
            $comics = $comicRepository->findAll();
        } else {
            // Regular user: Get comics for the current user
            $comics = $comicRepository->findBy(['owner' => $user]);
        }

        // Transform comics to array
        $comicsArray = [];
        foreach ($comics as $comic) {
            $fullCoverUrl = null;
            if ($comic->getCoverImagePath()) {
                try {
                    $filename = basename($comic->getCoverImagePath());
                    $fullCoverUrl = $this->urlGenerator->generate(
                        'api_comics_cover_image', // Ensure this matches the route name in getCoverImage
                        [
                            'userId' => $user->getId(),
                            'comicId' => $comic->getId(),
                            'filename' => $filename,
                        ],
                        UrlGeneratorInterface::ABSOLUTE_URL
                    );
                } catch (\Symfony\Component\Routing\Exception\RouteNotFoundException $e) {
                    // Log the error (e.g., using Monolog or error_log)
                    error_log("Route 'api_comics_cover_image' not found for comic ID " . $comic->getId() . ": " . $e->getMessage());
                    // $fullCoverUrl remains null
                } catch (\Exception $e) {
                    error_log("Error generating cover URL for comic ID " . $comic->getId() . ": " . $e->getMessage());
                    // $fullCoverUrl remains null
                }
            }

            $comicsArray[] = [
                'id' => $comic->getId(),
                'title' => $comic->getTitle(),
                'author' => $comic->getAuthor(),
                'publisher' => $comic->getPublisher(),
                'description' => $comic->getDescription(),
                'coverImagePath' => $fullCoverUrl,
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

        $comic = $entityManager->getRepository(Comic::class)->find($id);
        if (!$comic) {
            return $this->json(['message' => 'Comic not found'], Response::HTTP_NOT_FOUND);
        }

        // Check permissions: Admin can access any, user only their own
        if (!in_array('ROLE_ADMIN', $user->getRoles()) && $comic->getOwner() !== $user) {
            return $this->json(['message' => 'Access denied or comic not found'], Response::HTTP_FORBIDDEN); // Or HTTP_NOT_FOUND
        }

        // Get reading progress if exists
        $readingProgress = $entityManager->getRepository(ComicReadingProgress::class)
            ->findOneBy(['comic' => $comic, 'user' => $user]);

        // Transform comic to array
        $fullCoverUrl = null;
        if ($comic->getCoverImagePath()) {
            try {
                $filename = basename($comic->getCoverImagePath());
                $fullCoverUrl = $this->urlGenerator->generate(
                    'api_comics_cover_image', // Ensure this matches the route name in getCoverImage
                    [
                        'userId' => $user->getId(),
                        'comicId' => $comic->getId(),
                        'filename' => $filename,
                    ],
                    UrlGeneratorInterface::ABSOLUTE_URL
                );
            } catch (\Symfony\Component\Routing\Exception\RouteNotFoundException $e) {
                error_log("Route 'api_comics_cover_image' not found for comic ID " . $comic->getId() . ": " . $e->getMessage());
                // $fullCoverUrl remains null
            } catch (\Exception $e) {
                error_log("Error generating cover URL for comic ID " . $comic->getId() . ": " . $e->getMessage());
                // $fullCoverUrl remains null
            }
        }

        $comicArray = [
            'id' => $comic->getId(),
            'title' => $comic->getTitle(),
            'author' => $comic->getAuthor(),
            'publisher' => $comic->getPublisher(),
            'description' => $comic->getDescription(),
            'coverImagePath' => $fullCoverUrl,
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
        ComicService $comicService
    ): JsonResponse {
        // Get the current user
        $user = $this->getUser();
        if (!$user) {
            return $this->json(['message' => 'User not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        // Debug request information
        $requestInfo = [
            'content_type' => $request->headers->get('Content-Type'),
            'has_files' => $request->files->count() > 0,
            'file_keys' => array_keys($request->files->all()),
            'post_keys' => array_keys($request->request->all()),
            'request_method' => $request->getMethod(),
            'request_uri' => $request->getRequestUri(),
            'client_ip' => $request->getClientIp()
        ];
        
        // Log the request information
        error_log('Comic upload request: ' . json_encode($requestInfo));

        // Get uploaded file
        $comicFile = $request->files->get('file');
        if (!$comicFile) {
            error_log('No file found in request');
            
            // Check if there are any files in the request
            if ($request->files->count() > 0) {
                error_log('Files found but not with key "file": ' . json_encode(array_keys($request->files->all())));
            }
            
            return $this->json([
                'message' => 'No file uploaded', 
                'request_info' => $requestInfo
            ], Response::HTTP_BAD_REQUEST);
        }
        
        // Log file information
        $fileInfo = [
            'original_name' => $comicFile->getClientOriginalName(),
            'mime_type' => $comicFile->getMimeType(),
            'size' => $comicFile->getSize(),
            'error' => $comicFile->getError(),
            'extension' => $comicFile->getClientOriginalExtension()
        ];
        error_log('Uploaded file info: ' . json_encode($fileInfo));

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
            // Ensure upload directories exist
            $comicsDirectory = $this->getParameter('comics_directory');
            $userDirectory = $comicsDirectory . '/' . $user->getId();
            
            if (!file_exists($comicsDirectory)) {
                mkdir($comicsDirectory, 0777, true);
            }
            
            if (!file_exists($userDirectory)) {
                mkdir($userDirectory, 0777, true);
            }
            
            // Use the comic service to handle the upload
            $comic = $comicService->uploadComic(
                $comicFile,
                $user,
                $title,
                $author,
                $publisher,
                $description,
                $tags
            );

            return $this->json([
                'message' => 'Comic uploaded successfully',
                'comic' => [
                    'id' => $comic->getId(),
                    'title' => $comic->getTitle()
                ]
            ], Response::HTTP_CREATED);

        } catch (\Exception $e) {
            return $this->json([
                'message' => 'Upload failed: ' . $e->getMessage(),
                'file_info' => [
                    'name' => $comicFile ? $comicFile->getClientOriginalName() : 'No file',
                    'size' => $comicFile ? $comicFile->getSize() : 0,
                    'mime_type' => $comicFile ? $comicFile->getMimeType() : 'Unknown',
                    'error' => $comicFile ? $comicFile->getError() : 'No file'
                ],
                'request_info' => $requestInfo
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
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

        $comic = $entityManager->getRepository(Comic::class)->find($id);
        if (!$comic) {
            return $this->json(['message' => 'Comic not found'], Response::HTTP_NOT_FOUND);
        }

        // Check permissions: Admin can update any, user only their own
        if (!in_array('ROLE_ADMIN', $user->getRoles()) && $comic->getOwner() !== $user) {
            return $this->json(['message' => 'Access denied or comic not found'], Response::HTTP_FORBIDDEN); // Or HTTP_NOT_FOUND
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
    public function delete(
        string $id, 
        EntityManagerInterface $entityManager,
        ComicService $comicService
    ): JsonResponse {
        $user = $this->getUser();
        if (!$user) {
            return $this->json(['message' => 'User not authenticated'], Response::HTTP_UNAUTHORIZED);
        }
        
        $comic = $entityManager->getRepository(Comic::class)->find($id);
        
        if (!$comic) {
            return $this->json(['message' => 'Comic not found'], Response::HTTP_NOT_FOUND);
        }
        
        // Check permissions: Admin can delete any, user only their own
        // Assuming $comic->getOwner() is the correct method to get the owner User entity
        // If your Comic entity uses $comic->getUser(), please adjust accordingly.
        if (!in_array('ROLE_ADMIN', $user->getRoles()) && $comic->getOwner() !== $user) {
            return $this->json(['message' => 'You do not have permission to delete this comic'], Response::HTTP_FORBIDDEN);
        }
        
        try {
            $comicService->deleteComic($comic);
            $entityManager->remove($comic);
            $entityManager->flush();
            
            return $this->json(['message' => 'Comic deleted successfully']);
        } catch (\Exception $e) {
            return $this->json(['message' => 'Failed to delete comic: ' . $e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    #[Route('/test', name: 'test', methods: ['GET'])]
    public function testEndpoint(): JsonResponse
    {
        return $this->json([
            'message' => 'API endpoint is working',
            'timestamp' => time()
        ]);
    }
    
    #[Route('/upload/init', name: 'upload_init', methods: ['POST'])]
    public function initUpload(Request $request): JsonResponse
    {
        // Get the current user
        $user = $this->getUser();
        if (!$user) {
            return $this->json(['message' => 'User not authenticated'], Response::HTTP_UNAUTHORIZED);
        }
        
        try {
            $data = json_decode($request->getContent(), true);
            
            if (!isset($data['fileId']) || !isset($data['filename']) || !isset($data['totalChunks'])) {
                return $this->json(['message' => 'Missing required parameters'], Response::HTTP_BAD_REQUEST);
            }
            
            $fileId = $data['fileId'];
            $filename = $data['filename'];
            $totalChunks = (int)$data['totalChunks'];
            $metadata = $data['metadata'] ?? [];
            
            // Validate file extension
            $extension = pathinfo($filename, PATHINFO_EXTENSION);
            if (strtolower($extension) !== 'cbz') {
                return $this->json(['message' => 'Only CBZ files are allowed'], Response::HTTP_BAD_REQUEST);
            }
            
            // Create user-specific directory for chunks
            $userChunkDir = $this->tempUploadDir . '/' . $user->getId() . '/' . $fileId;
            if (!file_exists($userChunkDir)) {
                mkdir($userChunkDir, 0777, true);
            }
            
            // Save metadata
            file_put_contents(
                $userChunkDir . '/metadata.json', 
                json_encode([
                    'filename' => $filename,
                    'totalChunks' => $totalChunks,
                    'receivedChunks' => 0,
                    'metadata' => $metadata,
                    'userId' => $user->getId(),
                    'timestamp' => time()
                ])
            );
            
            error_log('Upload initialized: ' . json_encode([
                'fileId' => $fileId,
                'filename' => $filename,
                'totalChunks' => $totalChunks,
                'userChunkDir' => $userChunkDir
            ]));
            
            return $this->json([
                'message' => 'Upload initialized',
                'fileId' => $fileId,
                'chunksExpected' => $totalChunks
            ]);
        } catch (\Exception $e) {
            error_log('Error initializing upload: ' . $e->getMessage());
            return $this->json(['message' => 'Failed to initialize upload: ' . $e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
    
    #[Route('/upload/chunk', name: 'upload_chunk', methods: ['POST'])]
    public function uploadChunk(Request $request): JsonResponse
    {
        // Get the current user
        $user = $this->getUser();
        if (!$user) {
            return $this->json(['message' => 'User not authenticated'], Response::HTTP_UNAUTHORIZED);
        }
        
        try {
            $fileId = $request->request->get('fileId');
            $chunkIndex = (int)$request->request->get('chunkIndex');
            $totalChunks = (int)$request->request->get('totalChunks');
            $chunk = $request->files->get('chunk');
            
            if (!$fileId || !isset($chunkIndex) || !$chunk) {
                return $this->json(['message' => 'Missing required parameters'], Response::HTTP_BAD_REQUEST);
            }
            
            // Check if chunk is valid
            if (!$chunk->isValid()) {
                return $this->json(['message' => 'Invalid chunk: ' . $chunk->getErrorMessage()], Response::HTTP_BAD_REQUEST);
            }
            
            // Get user chunk directory
            $userChunkDir = $this->tempUploadDir . '/' . $user->getId() . '/' . $fileId;
            if (!file_exists($userChunkDir)) {
                return $this->json(['message' => 'Upload not initialized'], Response::HTTP_BAD_REQUEST);
            }
            
            // Load metadata
            $metadataPath = $userChunkDir . '/metadata.json';
            if (!file_exists($metadataPath)) {
                return $this->json(['message' => 'Upload metadata not found'], Response::HTTP_BAD_REQUEST);
            }
            
            $metadata = json_decode(file_get_contents($metadataPath), true);
            
            // Validate chunk index
            if ($chunkIndex < 0 || $chunkIndex >= $metadata['totalChunks']) {
                return $this->json(['message' => 'Invalid chunk index'], Response::HTTP_BAD_REQUEST);
            }
            
            // Save chunk
            $chunkPath = $userChunkDir . '/chunk_' . $chunkIndex;
            $chunk->move(dirname($chunkPath), basename($chunkPath));
            
            // Update metadata
            $metadata['receivedChunks']++;
            file_put_contents($metadataPath, json_encode($metadata));
            
            error_log('Chunk uploaded: ' . json_encode([
                'fileId' => $fileId,
                'chunkIndex' => $chunkIndex,
                'receivedChunks' => $metadata['receivedChunks'],
                'totalChunks' => $totalChunks
            ]));
            
            return $this->json([
                'message' => 'Chunk uploaded',
                'chunkIndex' => $chunkIndex,
                'chunksReceived' => $metadata['receivedChunks'],
                'chunksTotal' => $metadata['totalChunks']
            ]);
        } catch (\Exception $e) {
            error_log('Error uploading chunk: ' . $e->getMessage());
            return $this->json(['message' => 'Failed to upload chunk: ' . $e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
    
    #[Route('/upload/complete', name: 'upload_complete', methods: ['POST'])]
    public function completeUpload(
        Request $request, 
        EntityManagerInterface $entityManager,
        ComicService $comicService
    ): JsonResponse {
        // Get the current user
        $user = $this->getUser();
        if (!$user) {
            return $this->json(['message' => 'User not authenticated'], Response::HTTP_UNAUTHORIZED);
        }
        
        try {
            $data = json_decode($request->getContent(), true);
            
            if (!isset($data['fileId'])) {
                return $this->json(['message' => 'Missing fileId parameter'], Response::HTTP_BAD_REQUEST);
            }
            
            $fileId = $data['fileId'];
            
            // Get user chunk directory
            $userChunkDir = $this->tempUploadDir . '/' . $user->getId() . '/' . $fileId;
            if (!file_exists($userChunkDir)) {
                return $this->json(['message' => 'Upload not found'], Response::HTTP_BAD_REQUEST);
            }
            
            // Load metadata
            $metadataPath = $userChunkDir . '/metadata.json';
            if (!file_exists($metadataPath)) {
                return $this->json(['message' => 'Upload metadata not found'], Response::HTTP_BAD_REQUEST);
            }
            
            $metadata = json_decode(file_get_contents($metadataPath), true);
            
            // Check if all chunks are received
            if ($metadata['receivedChunks'] !== $metadata['totalChunks']) {
                return $this->json([
                    'message' => 'Not all chunks received',
                    'chunksReceived' => $metadata['receivedChunks'],
                    'chunksExpected' => $metadata['totalChunks']
                ], Response::HTTP_BAD_REQUEST);
            }
            
            // Combine chunks into final file
            $finalFilePath = $userChunkDir . '/' . $metadata['filename'];
            $finalFile = fopen($finalFilePath, 'wb');
            
            for ($i = 0; $i < $metadata['totalChunks']; $i++) {
                $chunkPath = $userChunkDir . '/chunk_' . $i;
                if (!file_exists($chunkPath)) {
                    fclose($finalFile);
                    return $this->json(['message' => 'Chunk ' . $i . ' is missing'], Response::HTTP_BAD_REQUEST);
                }
                
                $chunkData = file_get_contents($chunkPath);
                fwrite($finalFile, $chunkData);
                unlink($chunkPath); // Delete chunk after combining
            }
            
            fclose($finalFile);
            
            // Create a Symfony UploadedFile from the combined file
            $tempFile = new UploadedFile(
                $finalFilePath,
                $metadata['filename'],
                mime_content_type($finalFilePath),
                null,
                true // Test mode to avoid moving the file
            );
            
            // Extract metadata
            $comicMetadata = $metadata['metadata'];
            
            // Create comic in database
            $comic = $comicService->uploadComic(
                $tempFile,
                $user,
                $comicMetadata['title'],
                $comicMetadata['author'],
                $comicMetadata['publisher'],
                $comicMetadata['description'],
                $comicMetadata['tags'] ?? []
            );
            
            // Clean up temp directory
            $this->cleanupTempDirectory($userChunkDir);
            
            return $this->json([
                'message' => 'Upload completed successfully',
                'comic' => $comic->toArray()
            ]);
        } catch (\Exception $e) {
            error_log('Error completing upload: ' . $e->getMessage());
            return $this->json(['message' => 'Failed to complete upload: ' . $e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
    
    /**
     * Helper method to clean up temporary directory after upload
     */
    private function cleanupTempDirectory(string $directory): void
    {
        if (!file_exists($directory)) {
            return;
        }
        
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        
        foreach ($files as $file) {
            if ($file->isDir()) {
                rmdir($file->getRealPath());
            } else {
                unlink($file->getRealPath());
            }
        }
        
        rmdir($directory);
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

        // Always look for the comic in the user's directory first
        $comicsDirectory = $this->getParameter('comics_directory');
        $userDirectory = $comicsDirectory . '/' . $user->getId();
        $filePath = $userDirectory . '/' . $comic->getFilePath();
        
        // Fallback to old path if file doesn't exist in user directory
        if (!file_exists($filePath)) {
            $filePath = $comicsDirectory . '/' . $comic->getFilePath();
            
            // If still not found, return error
            if (!file_exists($filePath)) {
                return $this->json(['message' => 'Comic file not found'], Response::HTTP_NOT_FOUND);
            }
            
            // If found in the old location, move it to the user's directory for future access
            try {
                // Create user directory if it doesn't exist
                if (!file_exists($userDirectory)) {
                    mkdir($userDirectory, 0777, true);
                }
                
                // Copy the file to the user's directory
                copy($filePath, $userDirectory . '/' . $comic->getFilePath());
                
                // Update the file path to use the user's directory
                $filePath = $userDirectory . '/' . $comic->getFilePath();
            } catch (\Exception $e) {
                // If there's an error moving the file, just continue using the old path
                // We'll log this in a production environment
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

    #[Route('/cover/{userId}/{comicId}/{filename}', name: 'cover_image', methods: ['GET'])]
    public function getCoverImage(int $userId, int $comicId, string $filename, EntityManagerInterface $entityManager): Response
    {
        /** @var \App\Entity\User|null $currentUser */
        $currentUser = $this->getUser();
        if (!$currentUser) {
            return $this->json(['message' => 'Not authenticated.'], Response::HTTP_UNAUTHORIZED);
        }

        if ($currentUser->getId() !== $userId) {
            // Log this attempt, as it could be a sign of probing or misconfiguration
            error_log("Forbidden access attempt: User {$currentUser->getId()} tried to access cover for user {$userId}.");
            return $this->json(['message' => 'Forbidden.'], Response::HTTP_FORBIDDEN);
        }

        $comic = $entityManager->getRepository(Comic::class)->findOneBy(['id' => $comicId, 'owner' => $currentUser]);
        if (!$comic) {
            return $this->json(['message' => 'Comic not found or not owned by user.'], Response::HTTP_NOT_FOUND);
        }

        $coverPath = $comic->getCoverImagePath(); // This is relative to user's comic dir, e.g., "covers/COMIC_ID/file.jpg"
        if (!$coverPath) {
            return $this->json(['message' => 'Comic has no cover image path.'], Response::HTTP_NOT_FOUND);
        }
        
        $expectedFilename = basename($coverPath);
        if ($filename !== $expectedFilename) {
            error_log("Requested filename '{$filename}' does not match expected '{$expectedFilename}' for comic ID {$comicId} by user ID {$userId}");
            return $this->json(['message' => 'Invalid filename requested.'], Response::HTTP_NOT_FOUND);
        }

        // $this->comicsDirectory is the base path like "/var/www/public/uploads/comics"
        // $currentUser->getId() is the user's ID
        // $coverPath is "covers/{comic_id}/actual_cover.jpg"
        $absolutePath = $this->comicsDirectory . '/' . $currentUser->getId() . '/' . ltrim($coverPath, '/');

        if (!file_exists($absolutePath) || !is_readable($absolutePath)) {
            error_log("Cover file not found on disk or not readable: {$absolutePath} for comic ID {$comicId}, user ID {$userId}");
            return $this->json(['message' => 'Cover image file not found on server.'], Response::HTTP_NOT_FOUND);
        }

        // Use BinaryFileResponse to serve the image
        // This handles Content-Type, Content-Length, and other necessary headers.
        // It also supports range requests if the client asks for partial content.
        return new BinaryFileResponse($absolutePath);
    }
}
