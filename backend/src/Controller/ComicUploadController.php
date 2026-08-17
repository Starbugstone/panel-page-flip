<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Enum\ComicSourceType;
use App\Service\ComicFormatService;
use App\Service\ComicSerializer;
use App\Service\ComicService;
use App\Service\ComicUploadFilenameValidator;
use App\Service\LibraryFolderService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Receiving a comic file, one chunk at a time.
 *
 * Split from the rest of the comic API because it answers to different
 * pressures than anything else there: it is the only part that writes to a
 * staging directory, takes a lock, and enforces byte and chunk ceilings, and
 * the only part whose input is an attacker-supplied filename before anything
 * has validated it. Those are the reasons this code changes, and none of them
 * are reasons the library or the reader changes.
 */
#[Route('/api/comics', name: 'api_comics_')]
class ComicUploadController extends AbstractController
{
    use RequiresAuthenticatedUser;

    private const FILE_ID_REGEX = '/^[A-Za-z0-9\-]{8,64}$/';

    private string $tempUploadDir;

    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly int $uploadMaxChunkBytes,
        private readonly int $uploadMaxTotalBytes,
        private readonly int $uploadMaxTotalChunks,
        private readonly ComicUploadFilenameValidator $uploadFilenameValidator,
        private readonly ComicFormatService $comicFormatService,
        private readonly ComicSerializer $comicSerializer
    ) {
        $this->tempUploadDir = sys_get_temp_dir() . '/comic_uploads';
    }

    /**
     * Chunk staging lives under the system temp dir; create it lazily so a
     * filesystem write does not happen on every request that touches this
     * controller.
     */
    private function ensureTempUploadDir(string $directory): void
    {
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \RuntimeException('Failed to create the upload staging directory.');
        }
    }

    /**
     * Take the exclusive lock for one staged upload.
     *
     * A dedicated lock file rather than metadata.json itself: the metadata is
     * rewritten with file_put_contents, which truncates, and a lock held on a
     * handle to a file being replaced under it protects nothing.
     *
     * Blocking, not LOCK_NB. The chunks of one upload are meant to be admitted
     * one at a time, so a request that arrives mid-write should wait its turn
     * rather than be told to try again.
     *
     * @return resource
     */
    private function acquireUploadLock(string $userChunkDir)
    {
        $handle = fopen($userChunkDir . '/.chunk.lock', 'c');
        if ($handle === false) {
            throw new \RuntimeException('Failed to open the upload lock.');
        }

        if (!flock($handle, LOCK_EX)) {
            fclose($handle);

            throw new \RuntimeException('Failed to acquire the upload lock.');
        }

        return $handle;
    }

    /** @param resource $handle */
    private function releaseUploadLock($handle): void
    {
        flock($handle, LOCK_UN);
        fclose($handle);
    }

    private function assertSafeFileId(string $fileId): void
    {
        if (!preg_match(self::FILE_ID_REGEX, $fileId)) {
            throw new BadRequestHttpException('Invalid fileId.');
        }
    }

    private function assertSafeFilename(string $filename): string
    {
        $validated = $this->uploadFilenameValidator->validate($filename);
        $type = ComicSourceType::fromFilename($validated);
        if (!$this->comicFormatService->isEnabled($type)) {
            throw new BadRequestHttpException(sprintf('%s uploads are not enabled.', strtoupper($type->value)));
        }
        return $validated;
    }

    #[Route('/upload/init', name: 'upload_init', methods: ['POST'])]
    public function initUpload(Request $request, LibraryFolderService $folderService): JsonResponse
    {
        $user = $this->requireUser();
        
        try {
            $data = \App\Http\JsonRequestDecoder::decode($request);
            
            if (!isset($data['fileId']) || !isset($data['filename']) || !isset($data['totalChunks'])) {
                return $this->json(['message' => 'Missing required parameters'], Response::HTTP_BAD_REQUEST);
            }
            
            $fileId = (string) $data['fileId'];
            $this->assertSafeFileId($fileId);
            $filename = $this->assertSafeFilename((string) $data['filename']);
            $totalChunks = (int)$data['totalChunks'];
            $metadata = is_array($data['metadata'] ?? null) ? $data['metadata'] : [];

            // Validate the destination while the upload starts. It is checked
            // again after assembly, where a folder deleted during a long upload
            // intentionally falls back to root instead of losing the archive.
            if (array_key_exists('folderId', $metadata) && $metadata['folderId'] !== null && $metadata['folderId'] !== '') {
                if ((!is_int($metadata['folderId']) && !(is_string($metadata['folderId']) && ctype_digit($metadata['folderId'])))
                    || (int) $metadata['folderId'] < 1
                    || $folderService->findOwned($user, (int) $metadata['folderId']) === null
                ) {
                    return $this->json(['message' => 'Folder not found.'], Response::HTTP_BAD_REQUEST);
                }
                $metadata['folderId'] = (int) $metadata['folderId'];
            } else {
                $metadata['folderId'] = null;
            }

            if ($totalChunks < 1 || $totalChunks > $this->uploadMaxTotalChunks) {
                return $this->json(['message' => 'Invalid chunk count'], Response::HTTP_BAD_REQUEST);
            }
            
            // Create user-specific directory for chunks
            $userChunkDir = $this->tempUploadDir . '/' . $user->getId() . '/' . $fileId;
            $this->ensureTempUploadDir($userChunkDir);

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
        $user = $this->requireUser();
        
        try {
            $fileId = (string) $request->request->get('fileId');
            $this->assertSafeFileId($fileId);
            $chunkIndex = (int) $request->request->get('chunkIndex');
            $chunk = $request->files->get('chunk');

            if (!$chunk) {
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

            // Everything from here to the metadata write is one critical section,
            // held per upload.
            //
            // The client sends five chunks of the same upload at once, and this
            // is a read-modify-write of a single JSON file. Without the lock two
            // requests read the same metadata and the second write drops the
            // first one's entries: a lost chunkSizes entry undercounts the
            // staged total and lets the size limit below be walked past, and a
            // lost receivedChunks entry makes /upload/complete reject an upload
            // that did arrive in full.
            $lock = $this->acquireUploadLock($userChunkDir);

            try {
                $metadata = json_decode((string) file_get_contents($metadataPath), true);
                if (!is_array($metadata)) {
                    return $this->json(['message' => 'Upload metadata not found'], Response::HTTP_BAD_REQUEST);
                }

                // Validate chunk index. The key is checked rather than assumed:
                // initUpload always writes it, but a truncated or hand-edited
                // metadata file would otherwise compare against null and take
                // the wrong branch on a warning.
                if (!isset($metadata['totalChunks'])
                    || $chunkIndex < 0
                    || $chunkIndex >= (int) $metadata['totalChunks']
                ) {
                    return $this->json(['message' => 'Invalid chunk index'], Response::HTTP_BAD_REQUEST);
                }

                // Refuse the chunk that would take this upload past the size
                // limit, rather than waiting for /upload/complete to notice.
                //
                // Per-chunk and per-file limits together used to leave the
                // staging area unbounded: an upload could be initialised for the
                // maximum chunk count and every chunk sent at the maximum size,
                // filling the disk with several times the permitted total, and
                // only be rejected once the last chunk had already been written.
                // Nothing here is charged against the user's quota either, so
                // the files were free.
                $stagedBytes = array_sum(array_map('intval', $metadata['chunkSizes'] ?? []));
                $replacedBytes = (int) ($metadata['chunkSizes'][(string) $chunkIndex] ?? 0);
                if ($stagedBytes - $replacedBytes + (int) $chunk->getSize() > $this->uploadMaxTotalBytes) {
                    return $this->json(['message' => 'File too large'], Response::HTTP_REQUEST_ENTITY_TOO_LARGE);
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
            } finally {
                // Every path out, including the rejections above, which would
                // otherwise wedge the rest of the upload behind a held lock.
                $this->releaseUploadLock($lock);
            }
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
        ComicService $comicService,
        LibraryFolderService $folderService
    ): JsonResponse {
        $user = $this->requireUser();

        try {
            $data = \App\Http\JsonRequestDecoder::decode($request);

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
            
            // The same per-upload lock the chunk handler takes, for the same
            // reason: this reads the staged metadata, sums it, and unlinks the
            // chunk files as it assembles them. A chunk request overlapping any
            // of that could have its file deleted underneath it, or write
            // metadata that assembly has already read past. The app's own client
            // waits for every chunk before completing, so this is the case where
            // a client does not — but the staging area must be consistent
            // whatever the client does.
            $lock = $this->acquireUploadLock($userChunkDir);

            try {
                return $this->assembleUpload(
                    $userChunkDir,
                    $metadataPath,
                    $user,
                    $entityManager,
                    $comicService,
                    $folderService
                );
            } finally {
                $this->releaseUploadLock($lock);
            }
        } catch (BadRequestHttpException $e) {
            // Clean up if assembly has already occurred
            if (isset($userChunkDir) && file_exists($userChunkDir)) {
                $this->cleanupTempDirectory($userChunkDir);
            }
            return $this->json(['message' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        } catch (\Exception $e) {
            $this->logger->warning('Error completing upload.', ['user_id' => $user->getId(), 'exception' => $e]);
            // Clean up if assembly has already occurred
            if (isset($userChunkDir) && file_exists($userChunkDir)) {
                $this->cleanupTempDirectory($userChunkDir);
            }
            return $this->json(['message' => 'Failed to complete upload'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Turn a fully staged upload into a comic. Runs under the upload lock.
     */
    private function assembleUpload(
        string $userChunkDir,
        string $metadataPath,
        User $user,
        EntityManagerInterface $entityManager,
        ComicService $comicService,
        LibraryFolderService $folderService
    ): JsonResponse {
        $metadata = json_decode((string) file_get_contents($metadataPath), true);
        if (!is_array($metadata) || !isset($metadata['totalChunks'], $metadata['filename'])) {
            return $this->json(['message' => 'Upload metadata not found'], Response::HTTP_BAD_REQUEST);
        }

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

        // Friendly preflight; ComicService repeats the authoritative check
        // while holding the per-user storage lock.
        if ($comicService->wouldExceedQuota($user, $totalSize)) {
            return $this->json(['message' => 'User storage quota exceeded'], Response::HTTP_REQUEST_ENTITY_TOO_LARGE);
        }

        // Extract metadata. Only the title is required; the rest are optional
        // and may legitimately be absent from the init payload. Validate before
        // assembling so a rejected upload never leaves staged files behind: the
        // title comes from the init payload, so retrying can never succeed.
        $comicMetadata = is_array($metadata['metadata'] ?? null) ? $metadata['metadata'] : [];
        $title = trim((string) ($comicMetadata['title'] ?? ''));
        if ($title === '') {
            $this->cleanupTempDirectory($userChunkDir);

            return $this->json(['message' => 'Title is required'], Response::HTTP_BAD_REQUEST);
        }

        // Combine chunks into final file
        // The client filename is metadata only. Always assemble into a
        // server-controlled path so valid punctuation and Unicode never
        // influence filesystem path handling. The extension comes back through
        // the source type rather than off the filename for that reason: the
        // enum is what actually constrains it to a known set, so loosening the
        // filename validator can never put an arbitrary suffix on this path.
        $extension = ComicSourceType::fromFilename($filename)->value;
        $finalFilePath = $userChunkDir . '/assembled.' . $extension;
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

        // Create comic in database
        $comic = $comicService->uploadComic(
            $tempFile,
            $user,
            $title,
            $comicMetadata['author'] ?? null,
            $comicMetadata['publisher'] ?? null,
            $comicMetadata['description'] ?? null,
            $comicMetadata['tags'] ?? []
        );

        $folderService->placeUploadedComic(
            $user,
            $comic,
            isset($comicMetadata['folderId']) ? (int) $comicMetadata['folderId'] : null
        );

        // Clean up temp directory
        $this->cleanupTempDirectory($userChunkDir);

        return $this->json([
            'message' => 'Upload completed successfully',
            'comic' => $this->comicSerializer->serialize($comic, $user, false),
        ]);
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
}
