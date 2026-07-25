<?php

namespace App\Controller;

use App\Entity\Comic;
use App\Entity\ComicReadingProgress;
use App\Entity\Tag;
use App\Entity\User;
use App\Service\AdminAuditService;
use App\Service\ComicService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\String\Slugger\SluggerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use ZipArchive;

#[Route('/api/comics', name: 'api_comics_')]
class ComicController extends AbstractController
{
    private const FILE_ID_REGEX = '/^[A-Za-z0-9\-]{8,64}$/';

    private string $tempUploadDir;
    private RequestStack $requestStack;
    private UrlGeneratorInterface $urlGenerator;
    
    public function __construct(
        private string $comicsDirectory,
        RequestStack $requestStack,
        UrlGeneratorInterface $urlGenerator,
        private readonly LoggerInterface $logger,
        private readonly int $uploadMaxChunkBytes,
        private readonly int $uploadMaxTotalBytes,
        private readonly int $uploadMaxTotalChunks,
        private readonly int $uploadUserQuotaBytes
    ) {
        $this->tempUploadDir = sys_get_temp_dir() . '/comic_uploads';
        $this->requestStack = $requestStack;
        $this->urlGenerator = $urlGenerator;
        
        // Ensure temp directory exists
        if (!file_exists($this->tempUploadDir)) {
            mkdir($this->tempUploadDir, 0775, true);
        }
    }

    private function assertSafeFileId(string $fileId): void
    {
        if (!preg_match(self::FILE_ID_REGEX, $fileId)) {
            throw new BadRequestHttpException('Invalid fileId.');
        }
    }

    private function assertSafeFilename(string $filename): string
    {
        $base = basename($filename);
        if (!preg_match('/^[A-Za-z0-9._\- ]{1,200}\.cbz$/i', $base)) {
            throw new BadRequestHttpException('Invalid filename.');
        }

        return $base;
    }

    // Removed getPublicBaseUrlForUploads() method as it's no longer needed.

    /**
     * Check if user has exceeded search rate limit
     * Simple implementation using session storage
     */
    private function checkSearchRateLimit(Request $request): ?JsonResponse
    {
        $session = $request->getSession();
        $now = time();
        $searchHistory = $session->get('search_history', []);
        
        // Keep only searches from the last minute
        $searchHistory = array_filter($searchHistory, function($timestamp) use ($now) {
            return $now - $timestamp < 60; // 1 minute window
        });
        
        // Check if user has made too many searches
        if (count($searchHistory) >= 10) { // Max 10 searches per minute
            return $this->json([
                'message' => 'Rate limit exceeded. Please try again later.',
                'retryAfter' => 60 - ($now - min($searchHistory)) // Seconds until oldest search expires
            ], Response::HTTP_TOO_MANY_REQUESTS);
        }
        
        // Add current search timestamp
        $searchHistory[] = $now;
        $session->set('search_history', $searchHistory);
        
        return null;
    }
    
    #[Route('', name: 'list', methods: ['GET'])]
    public function list(Request $request, EntityManagerInterface $entityManager): JsonResponse
    {
        // Get the current user
        $user = $this->getUser();
        if (!$user) {
            return $this->json(['message' => 'User not authenticated'], Response::HTTP_UNAUTHORIZED);
        }
        
        // Get search parameters
        $search = $request->query->get('search');
        $tagsParam = $request->query->get('tags');
        
        // Apply rate limiting only when search or tags parameters are present
        if ($search || $tagsParam) {
            // Check rate limit
            $rateLimitResponse = $this->checkSearchRateLimit($request);
            if ($rateLimitResponse) {
                return $rateLimitResponse;
            }
        }

        $qb = $entityManager->createQueryBuilder();
        $qb->select('c')
            ->from(Comic::class, 'c');

        // Check if we're in admin context - only consider this parameter if user is an admin
        $adminContext = $request->query->get('adminContext') === 'true' && in_array('ROLE_ADMIN', $user->getRoles());
        
        // User Ownership Filter - only show all comics to admins in admin context
        if (!$adminContext) {
            // For non-admins or admins outside admin context, only show their own comics
            $qb->andWhere('c.owner = :owner')
                ->setParameter('owner', $user);
        }

        // Search Filter
        if ($search) {
            $qb->andWhere($qb->expr()->orX(
                $qb->expr()->like('LOWER(c.title)', ':search'),
                $qb->expr()->like('LOWER(c.description)', ':search'),
                $qb->expr()->like('LOWER(c.author)', ':search'),
                $qb->expr()->like('LOWER(c.publisher)', ':search')
            ))
            ->setParameter('search', '%' . strtolower($search) . '%');
        }

        // Tags Filter - More efficient approach using JOIN, GROUP BY, and HAVING
        if ($tagsParam) {
            $tagNames = array_filter(array_map('trim', explode(',', $tagsParam)));
            if (!empty($tagNames)) {
                $qb->join('c.tags', 't')
                   ->andWhere('LOWER(t.name) IN (:tagNames)')
                   ->setParameter('tagNames', array_map('strtolower', $tagNames))
                   ->groupBy('c.id')
                   ->having('COUNT(DISTINCT t.id) = :tagCount')
                   ->setParameter('tagCount', count($tagNames));
            }
        }
        
        $comics = $qb->getQuery()->getResult();

        // Transform comics to array
        $comicsArray = [];
        foreach ($comics as $comic) {
            $fullCoverUrl = null;
            if ($comic->getCoverImagePath()) {
                try {
                    $filename = basename($comic->getCoverImagePath());
                    // Manually construct the URL path to avoid using internal Docker hostnames
                    $fullCoverUrl = '/api/comics/cover/' . $comic->getOwner()->getId() . '/' . $comic->getId() . '/' . $filename;
                } catch (\Exception $e) {
                    $this->logger->debug('Could not generate cover URL.', ['comic_id' => $comic->getId()]);
                    // $fullCoverUrl remains null
                }
            }

            // Get reading progress if exists
            // Ensure $user is available in this scope for reading progress.
            // It should be, as it's defined at the beginning of the method.
            $readingProgress = $entityManager->getRepository(ComicReadingProgress::class)
                ->findOneBy(['comic' => $comic, 'user' => $user]);

            $comicData = [
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

            if ($adminContext) {
                $owner = $comic->getOwner();
                $comicData['owner'] = [
                    'id' => $owner?->getId(),
                    'email' => $owner?->getEmail(),
                    'name' => $owner?->getName(),
                ];
            }

            $comicsArray[] = $comicData;
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
                // Manually construct the URL path to avoid using internal Docker hostnames
                $fullCoverUrl = '/api/comics/cover/' . $comic->getOwner()->getId() . '/' . $comic->getId() . '/' . $filename;
            } catch (\Exception $e) {
                $this->logger->debug('Could not generate cover URL.', ['comic_id' => $comic->getId()]);
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
            ] : null,
            'owner' => [
                'id' => $comic->getOwner()?->getId(),
                'email' => $comic->getOwner()?->getEmail(),
                'name' => $comic->getOwner()?->getName(),
            ],
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

        // Get uploaded file
        $comicFile = $request->files->get('file');
        if (!$comicFile) {
            return $this->json(['message' => 'No file uploaded'], Response::HTTP_BAD_REQUEST);
        }

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
            $this->logger->warning('Comic upload failed.', ['user_id' => $user->getId(), 'exception' => $e]);
            return $this->json([
                'message' => 'Upload failed: ' . $e->getMessage(),
            ], Response::HTTP_BAD_REQUEST);
        }
    }

    #[Route('/{id}', name: 'update', methods: ['PUT', 'PATCH'])]
    public function update(
        int $id, 
        Request $request, 
        EntityManagerInterface $entityManager,
        ValidatorInterface $validator,
        AdminAuditService $auditService
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

        $metadataBefore = [
            'title' => $comic->getTitle(),
            'author' => $comic->getAuthor(),
            'publisher' => $comic->getPublisher(),
            'description' => $comic->getDescription(),
        ];
        $tagOwner = $comic->getOwner() ?? $user;

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
                $tagName = is_array($tagName) ? ($tagName['name'] ?? '') : $tagName;
                if (!is_string($tagName) || trim($tagName) === '') {
                    continue;
                }
                $tagName = trim($tagName);

                // Check if tag exists for the comic owner
                $tag = $entityManager->getRepository(Tag::class)->findOneBy([
                    'name' => $tagName,
                    'creator' => $tagOwner,
                ]);
                if (!$tag) {
                    // Create new tag
                    $tag = new Tag();
                    $tag->setName($tagName);
                    $tag->setCreator($tagOwner);
                    $entityManager->persist($tag);
                }
                $comic->addTag($tag);
            }
        }

        if (in_array('ROLE_ADMIN', $user->getRoles(), true) && $comic->getOwner()?->getId() !== $user->getId()) {
            $auditService->log($user, 'comic_update', 'comic', $comic->getId(), [
                'ownerId' => $comic->getOwner()?->getId(),
                'before' => $metadataBefore,
                'after' => [
                    'title' => $comic->getTitle(),
                    'author' => $comic->getAuthor(),
                    'publisher' => $comic->getPublisher(),
                    'description' => $comic->getDescription(),
                ],
            ]);
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
        if (!in_array('ROLE_ADMIN', $user->getRoles()) && $comic->getOwner() !== $user) {
            return $this->json(['message' => 'You do not have permission to delete this comic'], Response::HTTP_FORBIDDEN);
        }
        
        try {
            // Use a transaction to ensure all operations succeed or fail together
            $entityManager->beginTransaction();
            
            // First delete the files using the service
            $comicService->deleteComic($comic);
            
            // The entity removal will cascade to reading progress thanks to the relationship setup
            $entityManager->remove($comic);
            $entityManager->flush();
            
            $entityManager->commit();
            
            return $this->json(['message' => 'Comic deleted successfully']);
        } catch (\Exception $e) {
            // Rollback the transaction if anything fails
            if ($entityManager->getConnection()->isTransactionActive()) {
                $entityManager->rollback();
            }
            
            return $this->json(['message' => 'Failed to delete comic: ' . $e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    #[Route('/{id}/reading-progress/reset', name: 'reset_reading_progress', methods: ['POST'])]
    public function resetReadingProgress(int $id, EntityManagerInterface $entityManager): JsonResponse
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

        // Check permissions: Admin can reset any, user only their own
        if (!in_array('ROLE_ADMIN', $user->getRoles()) && $comic->getOwner() !== $user) {
            return $this->json(['message' => 'Access denied or comic not found'], Response::HTTP_FORBIDDEN);
        }

        // Find and remove reading progress
        $readingProgress = $entityManager->getRepository(ComicReadingProgress::class)
            ->findOneBy(['comic' => $comic, 'user' => $user]);

        if ($readingProgress) {
            $entityManager->remove($readingProgress);
            $entityManager->flush();
        }

        return $this->json(['message' => 'Reading progress reset successfully']);
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
            
            $fileId = (string) $data['fileId'];
            $this->assertSafeFileId($fileId);
            $filename = $this->assertSafeFilename((string) $data['filename']);
            $totalChunks = (int)$data['totalChunks'];
            $metadata = $data['metadata'] ?? [];

            if ($totalChunks < 1 || $totalChunks > $this->uploadMaxTotalChunks) {
                return $this->json(['message' => 'Invalid chunk count'], Response::HTTP_BAD_REQUEST);
            }
            
            // Create user-specific directory for chunks
            $userChunkDir = $this->tempUploadDir . '/' . $user->getId() . '/' . $fileId;
            if (!file_exists($userChunkDir)) {
                mkdir($userChunkDir, 0775, true);
            }
            
            // Save metadata
            file_put_contents(
                $userChunkDir . '/metadata.json', 
                json_encode([
                    'filename' => $filename,
                    'totalChunks' => $totalChunks,
                    'receivedChunks' => [],
                    'chunkSizes' => [],
                    'metadata' => $metadata,
                    'userId' => $user->getId(),
                    'timestamp' => time()
                ])
            );
            
            return $this->json([
                'message' => 'Upload initialized',
                'fileId' => $fileId,
                'chunksExpected' => $totalChunks
            ]);
        } catch (BadRequestHttpException $e) {
            return $this->json(['message' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        } catch (\Exception $e) {
            $this->logger->warning('Error initializing upload.', ['user_id' => $user->getId(), 'exception' => $e]);
            return $this->json(['message' => 'Failed to initialize upload'], Response::HTTP_INTERNAL_SERVER_ERROR);
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
            $fileId = (string) $request->request->get('fileId');
            $this->assertSafeFileId($fileId);
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

            if ((int) $chunk->getSize() > $this->uploadMaxChunkBytes) {
                return $this->json(['message' => 'Chunk is too large'], Response::HTTP_REQUEST_ENTITY_TOO_LARGE);
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
            $metadata['receivedChunks'] = $metadata['receivedChunks'] ?? [];
            if (!in_array($chunkIndex, $metadata['receivedChunks'], true)) {
                $metadata['receivedChunks'][] = $chunkIndex;
            }
            $metadata['chunkSizes'] = $metadata['chunkSizes'] ?? [];
            $metadata['chunkSizes'][(string) $chunkIndex] = (int) filesize($chunkPath);
            file_put_contents($metadataPath, json_encode($metadata));
            
            return $this->json([
                'message' => 'Chunk uploaded',
                'chunkIndex' => $chunkIndex,
                'chunksReceived' => count($metadata['receivedChunks']),
                'chunksTotal' => $metadata['totalChunks']
            ]);
        } catch (BadRequestHttpException $e) {
            return $this->json(['message' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        } catch (\Exception $e) {
            $this->logger->warning('Error uploading chunk.', ['user_id' => $user->getId(), 'exception' => $e]);
            return $this->json(['message' => 'Failed to upload chunk'], Response::HTTP_INTERNAL_SERVER_ERROR);
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
            
            $fileId = (string) $data['fileId'];
            $this->assertSafeFileId($fileId);
            
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
            $filename = $this->assertSafeFilename((string) $metadata['filename']);
            $receivedChunks = $metadata['receivedChunks'] ?? [];
            
            // Check if all chunks are received
            if (count($receivedChunks) !== (int) $metadata['totalChunks']) {
                return $this->json([
                    'message' => 'Not all chunks received',
                    'chunksReceived' => count($receivedChunks),
                    'chunksExpected' => $metadata['totalChunks']
                ], Response::HTTP_BAD_REQUEST);
            }

            $totalSize = array_sum(array_map('intval', $metadata['chunkSizes'] ?? []));
            if ($totalSize > $this->uploadMaxTotalBytes) {
                return $this->json(['message' => 'File too large'], Response::HTTP_REQUEST_ENTITY_TOO_LARGE);
            }

            $currentUsage = (int) $entityManager->createQueryBuilder()
                ->select('COALESCE(SUM(c.fileSize), 0)')
                ->from(Comic::class, 'c')
                ->where('c.owner = :owner')
                ->setParameter('owner', $user)
                ->getQuery()
                ->getSingleScalarResult();

            if ($currentUsage + $totalSize > $this->uploadUserQuotaBytes) {
                return $this->json(['message' => 'User storage quota exceeded'], Response::HTTP_REQUEST_ENTITY_TOO_LARGE);
            }
            
            // Combine chunks into final file
            $finalFilePath = $userChunkDir . '/' . $filename;
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
                $filename,
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
        } catch (BadRequestHttpException $e) {
            return $this->json(['message' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        } catch (\Exception $e) {
            $this->logger->warning('Error completing upload.', ['user_id' => $user->getId(), 'exception' => $e]);
            return $this->json(['message' => 'Failed to complete upload'], Response::HTTP_INTERNAL_SERVER_ERROR);
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

        $comic = $entityManager->getRepository(Comic::class)->find($id);
        if (!$comic) {
            return $this->json(['message' => 'Comic not found'], Response::HTTP_NOT_FOUND);
        }

        if ($comic->getOwner()?->getId() !== $user->getId() && !in_array('ROLE_ADMIN', $user->getRoles(), true)) {
            return $this->json(['message' => 'Comic not found'], Response::HTTP_NOT_FOUND);
        }

        // Validate page number
        if ($page < 1 || ($comic->getPageCount() !== null && $page > $comic->getPageCount())) {
            return $this->json(['message' => 'Invalid page number'], Response::HTTP_BAD_REQUEST);
        }

        // Always look for the comic in the user's directory first
        $comicsDirectory = $this->getParameter('comics_directory');
        $owner = $comic->getOwner();
        $userDirectory = $comicsDirectory . '/' . $owner->getId();
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

        $comic = $entityManager->getRepository(Comic::class)->find($id);
        if (!$comic) {
            return $this->json(['message' => 'Comic not found'], Response::HTTP_NOT_FOUND);
        }

        $isAdminReadingAnotherUsersComic = in_array('ROLE_ADMIN', $user->getRoles(), true) && $comic->getOwner()?->getId() !== $user->getId();
        if ($comic->getOwner()?->getId() !== $user->getId() && !$isAdminReadingAnotherUsersComic) {
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

        if ($isAdminReadingAnotherUsersComic) {
            return $this->json([
                'message' => 'Admin read-only progress ignored',
                'progress' => [
                    'currentPage' => $currentPage,
                    'lastReadAt' => (new \DateTimeImmutable())->format('c'),
                    'completed' => $completed,
                ],
            ]);
        }

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

        if ($currentUser->getId() !== $userId && !in_array('ROLE_ADMIN', $currentUser->getRoles(), true)) {
            // Log this attempt, as it could be a sign of probing or misconfiguration
            $this->logger->warning('Forbidden cover access attempt.', [
                'current_user_id' => $currentUser->getId(),
                'target_user_id' => $userId,
            ]);
            return $this->json(['message' => 'Forbidden.'], Response::HTTP_FORBIDDEN);
        }

        $owner = $entityManager->getRepository(User::class)->find($userId);
        $comic = $owner ? $entityManager->getRepository(Comic::class)->findOneBy(['id' => $comicId, 'owner' => $owner]) : null;
        if (!$comic) {
            return $this->json(['message' => 'Comic not found or not owned by user.'], Response::HTTP_NOT_FOUND);
        }

        $coverPath = $comic->getCoverImagePath(); // This is relative to user's comic dir, e.g., "covers/COMIC_ID/file.jpg"
        if (!$coverPath) {
            return $this->json(['message' => 'Comic has no cover image path.'], Response::HTTP_NOT_FOUND);
        }
        
        $expectedFilename = basename($coverPath);
        if ($filename !== $expectedFilename) {
            $this->logger->warning('Invalid cover filename requested.', ['comic_id' => $comicId, 'user_id' => $userId]);
            return $this->json(['message' => 'Invalid filename requested.'], Response::HTTP_NOT_FOUND);
        }

        // $this->comicsDirectory is the base path like "/var/www/public/uploads/comics"
        // $userId is the comic owner's ID
        // $coverPath is "covers/{comic_id}/actual_cover.jpg"
        $absolutePath = $this->comicsDirectory . '/' . $userId . '/' . ltrim($coverPath, '/');

        if (!file_exists($absolutePath) || !is_readable($absolutePath)) {
            $this->logger->warning('Cover file not found or unreadable.', ['comic_id' => $comicId, 'user_id' => $userId]);
            $placeholderPath = $this->getParameter('kernel.project_dir') . '/public/comic.png';
            if (is_readable($placeholderPath)) {
                return new BinaryFileResponse($placeholderPath);
            }

            return $this->json(['message' => 'Cover image file not found on server.'], Response::HTTP_NOT_FOUND);
        }

        // Use BinaryFileResponse to serve the image
        // This handles Content-Type, Content-Length, and other necessary headers.
        // It also supports range requests if the client asks for partial content.
        return new BinaryFileResponse($absolutePath);
    }
}
