<?php

namespace App\Controller;

use App\Entity\Comic;
use App\Entity\ComicReadingProgress;
use App\Entity\ComicShare;
use App\Entity\Tag;
use App\Entity\User;
use App\Repository\ComicRepository;
use App\Repository\ComicShareRepository;
use App\Repository\TagRepository;
use App\Security\Voter\ComicVoter;
use App\Service\AdminAuditService;
use App\Service\ComicSerializer;
use App\Service\ComicShareService;
use App\Service\ComicUploadFilenameValidator;
use App\Service\ComicService;
use App\Service\Pagination\PaginationRequest;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpKernel\EventListener\AbstractSessionListener;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use ZipArchive;

#[Route('/api/comics', name: 'api_comics_')]
class ComicController extends AbstractController
{
    private const FILE_ID_REGEX = '/^[A-Za-z0-9\-]{8,64}$/';
    private const ASSEMBLED_UPLOAD_FILENAME = 'assembled.cbz';

    /**
     * A stored cover filename carries a uniqid suffix, so its URL changes
     * whenever the cover is regenerated. The bytes behind a given cover URL can
     * therefore never change and the browser may keep them indefinitely.
     */
    private const COVER_CACHE_SECONDS = 31_536_000;

    /**
     * The placeholder is served from the cover URL of a comic whose file is
     * missing, so that URL is not versioned and must stay revalidatable. A short
     * lifetime plus a conditional request keeps repeat views cheap without
     * pinning a "missing cover" answer in the cache for a year.
     */
    private const COVER_PLACEHOLDER_CACHE_SECONDS = 300;

    /**
     * A page URL carries no version, and nothing in the app replaces a comic's
     * archive in place, so the bytes behind one are stable in practice. A day is
     * long enough to cover a reading session and a return to it, short enough
     * that an archive swapped by hand is not served stale for a week. The ETag
     * makes the revalidation after that a cheap 304.
     */
    private const PAGE_CACHE_SECONDS = 86_400;

    private string $tempUploadDir;

    public function __construct(
        private readonly string $comicsDirectory,
        private readonly LoggerInterface $logger,
        private readonly int $uploadMaxChunkBytes,
        private readonly int $uploadMaxTotalBytes,
        private readonly int $uploadMaxTotalChunks,
        private readonly int $uploadUserQuotaBytes,
        private readonly ComicUploadFilenameValidator $uploadFilenameValidator,
        private readonly ComicSerializer $comicSerializer,
        private readonly ComicShareRepository $shareRepository,
        private readonly ManagerRegistry $managerRegistry
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
        return $this->uploadFilenameValidator->validate($filename);
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
        if (!$user instanceof User) {
            return $this->json(['message' => 'User not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        // Get search parameters
        $search = $request->query->get('search');
        $tagsParam = $request->query->get('tags');
        $tagNames = $tagsParam
            ? array_values(array_unique(array_filter(array_map('trim', explode(',', $tagsParam)))))
            : [];
        
        // Check if we're in admin context - only consider this parameter if user is an admin
        $adminContext = $request->query->get('adminContext') === 'true' && in_array('ROLE_ADMIN', $user->getRoles());

        // Apply rate limiting only when search or tags parameters are present.
        // The admin table is exempt: its search is debounced but server-side, so
        // ten keystrokes a minute is a normal amount of typing, and the query it
        // runs is bounded by the page size.
        if (!$adminContext && ($search || $tagsParam)) {
            // Check rate limit
            $rateLimitResponse = $this->checkSearchRateLimit($request);
            if ($rateLimitResponse) {
                return $rateLimitResponse;
            }
        }

        // The admin table pages through every owner's comics, so it uses the
        // paginated repository query. A user's own library is still returned
        // whole: the dashboard filters and groups it client-side.
        if ($adminContext) {
            $pagination = PaginationRequest::fromRequest($request, ComicRepository::ADMIN_SORT_FIELDS, 'uploadedAt');
            $ownerId = $request->query->has('ownerId') ? $request->query->getInt('ownerId') : null;

            /** @var ComicRepository $comicRepository */
            $comicRepository = $entityManager->getRepository(Comic::class);
            $page = $comicRepository->findAdminPage($pagination, $ownerId);
            $comics = $this->comicSerializer->serializeMany($page->items, $user, true);

            return $this->json([
                'items' => $comics,
                'comics' => $comics,
                'pagination' => $page->toArray(),
            ]);
        }

        $qb = $entityManager->createQueryBuilder();
        $qb->select('c')
            ->from(Comic::class, 'c');

        // User Ownership Filter - only show all comics to admins in admin context
        if (!$adminContext) {
            // A collection is what the user owns plus what has been shared with
            // them and not hidden. The shared half is resolved to ids first so
            // the search and tag filters below apply to both halves through one
            // query rather than two lists merged afterwards.
            $ownership = $request->query->get('ownership', 'all');
            if (!in_array($ownership, ['all', 'mine', 'shared'], true)) {
                $ownership = 'all';
            }

            $sharedComicIds = $ownership === 'mine'
                ? []
                : $this->shareRepository->findVisibleCollectionComicIds($user);

            if ($ownership === 'shared') {
                // An empty IN () is not valid DQL, and a user with no shares
                // must get an empty list rather than everybody's comics.
                if ($sharedComicIds === []) {
                    return $this->json(['comics' => []]);
                }
                $qb->andWhere('c.id IN (:sharedComicIds)')
                    ->setParameter('sharedComicIds', $sharedComicIds);
            } elseif ($sharedComicIds === []) {
                $qb->andWhere('c.owner = :owner')->setParameter('owner', $user);
            } else {
                $qb->andWhere($qb->expr()->orX('c.owner = :owner', 'c.id IN (:sharedComicIds)'))
                    ->setParameter('owner', $user)
                    ->setParameter('sharedComicIds', $sharedComicIds);
            }

            /** @var TagRepository $tagRepository */
            $tagRepository = $entityManager->getRepository(Tag::class);
            if (!$tagRepository->hasLibraryHidingGlobalTag($tagNames)) {
                $hiddenTagSubquery = $entityManager->createQueryBuilder()
                    ->select('1')
                    ->from(Tag::class, 'libraryHidingTag')
                    ->join('libraryHidingTag.comics', 'hiddenComic')
                    ->where('hiddenComic = c')
                    ->andWhere('libraryHidingTag.hideFromLibrary = true')
                    ->getDQL();
                $qb->andWhere($qb->expr()->not($qb->expr()->exists($hiddenTagSubquery)));
            }
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
        if ($tagNames !== []) {
            $qb->join('c.tags', 't')
                ->andWhere('LOWER(t.name) IN (:tagNames)')
                ->setParameter('tagNames', array_map('strtolower', $tagNames))
                ->groupBy('c.id')
                ->having('COUNT(DISTINCT t.id) = :tagCount')
                ->setParameter('tagCount', count($tagNames));
        }
        
        $comics = $qb->getQuery()->getResult();

        return $this->json([
            'comics' => $this->comicSerializer->serializeMany($comics, $user, $adminContext),
        ]);
    }

    #[Route('', name: 'batch_update', methods: ['PATCH'])]
    public function batchUpdate(
        Request $request,
        EntityManagerInterface $entityManager,
        ComicShareService $shareService
    ): JsonResponse {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->json(['message' => 'User not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        $data = json_decode($request->getContent(), true);
        if (!is_array($data)) {
            return $this->json(['message' => 'Invalid JSON payload'], Response::HTTP_BAD_REQUEST);
        }

        $updates = $this->normaliseComicUpdates($data['updates'] ?? null);
        if ($updates === []) {
            return $this->json(['message' => 'A valid updates array is required'], Response::HTTP_BAD_REQUEST);
        }
        $comicIds = array_column($updates, 'id');

        $comics = $entityManager->getRepository(Comic::class)->findBy([
            'id' => $comicIds,
            'owner' => $user,
        ]);
        if (count($comics) !== count($comicIds)) {
            return $this->json(['message' => 'One or more comics were not found'], Response::HTTP_NOT_FOUND);
        }

        $comicsById = [];
        foreach ($comics as $comic) {
            $comicsById[$comic->getId()] = $comic;
        }
        $tagsByName = [];
        $getTag = function (string $tagName) use (&$tagsByName, $user, $entityManager): Tag {
            $tagKey = mb_strtolower($tagName);
            if (isset($tagsByName[$tagKey])) {
                return $tagsByName[$tagKey];
            }

            /** @var TagRepository $tagRepository */
            $tagRepository = $entityManager->getRepository(Tag::class);
            $tag = $tagRepository->findAvailableByName($tagName, $user);
            if (!$tag) {
                $tag = (new Tag())->setName($tagName)->setCreator($user);
                $entityManager->persist($tag);
            }

            return $tagsByName[$tagKey] = $tag;
        };

        foreach ($updates as $update) {
            $comic = $comicsById[$update['id']];
            $changes = $update['changes'];

            if (array_key_exists('title', $changes)) {
                $comic->setTitle($changes['title']);
            }
            foreach (['author', 'publisher', 'description'] as $field) {
                if (array_key_exists($field, $changes)) {
                    $setter = 'set' . ucfirst($field);
                    $comic->{$setter}($changes[$field]);
                }
            }

            if (array_key_exists('explicitContent', $changes)) {
                $wasExplicit = $comic->isExplicitContent();
                $comic->setExplicitContent($changes['explicitContent']);

                // Same rule as the single-comic update: newly explicit means
                // every live share loses its confirmation and the recipient has
                // to make the declaration again.
                if (!$wasExplicit && $changes['explicitContent']) {
                    $shareService->regateSharesForComic($comic);
                }
            }

            if (array_key_exists('tags', $changes)) {
                foreach ($comic->getTags()->toArray() as $tag) {
                    $comic->removeTag($tag);
                }
                foreach ($changes['tags'] as $tagName) {
                    $comic->addTag($getTag($tagName));
                }
            }

            foreach ($changes['addTags'] ?? [] as $tagName) {
                $comic->addTag($getTag($tagName));
            }
        }
        $entityManager->flush();

        return $this->json([
            'message' => sprintf('%d comic(s) updated', count($comics)),
            'updatedComicIds' => $comicIds,
        ]);
    }

    #[Route('', name: 'batch_delete', methods: ['DELETE'])]
    public function batchDelete(
        Request $request,
        EntityManagerInterface $entityManager,
        ComicService $comicService,
        ComicShareService $shareService
    ): JsonResponse {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->json(['message' => 'User not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        $data = json_decode($request->getContent(), true);
        $comicIds = is_array($data) ? $this->normaliseBulkComicIds($data['comicIds'] ?? null) : [];
        if ($comicIds === []) {
            return $this->json(['message' => 'comicIds are required'], Response::HTTP_BAD_REQUEST);
        }

        $comics = $entityManager->getRepository(Comic::class)->findBy([
            'id' => $comicIds,
            'owner' => $user,
        ]);
        if (count($comics) !== count($comicIds)) {
            return $this->json(['message' => 'One or more comics were not found'], Response::HTTP_NOT_FOUND);
        }

        $orphanedComics = [];
        foreach ($comics as $comic) {
            if (!$comicService->comicArchiveExists($comic)) {
                $orphanedComics[] = [
                    'id' => $comic->getId(),
                    'title' => $comic->getTitle(),
                    'fileName' => basename((string) $comic->getFilePath()),
                ];
            }
        }

        $confirmOrphaned = ($data['confirmOrphaned'] ?? false) === true;
        if ($orphanedComics !== [] && !$confirmOrphaned) {
            return $this->json([
                'code' => 'orphaned_comics_confirmation_required',
                'message' => 'One or more comic files are missing. Confirm removal of the orphaned library entries.',
                'orphanedComics' => $orphanedComics,
            ], Response::HTTP_CONFLICT);
        }

        $quarantinedFiles = [];
        try {
            $entityManager->beginTransaction();
            foreach ($comics as $comic) {
                // Same contract as the single delete: recipients are told why
                // the comic went away, in the transaction that removes it.
                $shareService->tombstoneSharesForComic($comic, ComicShare::REASON_OWNER_DELETED);
                array_push($quarantinedFiles, ...$comicService->quarantineComicFiles($comic));
                $entityManager->remove($comic);
            }
            $entityManager->flush();
            $entityManager->commit();
        } catch (\Throwable $exception) {
            if ($entityManager->getConnection()->isTransactionActive()) {
                $entityManager->rollback();
            }

            try {
                $comicService->restoreQuarantinedFiles($quarantinedFiles);
            } catch (\Throwable $restoreException) {
                $this->logger->critical('Bulk comic deletion failed and quarantined files could not be restored.', [
                    'exception' => $restoreException,
                    'comic_ids' => $comicIds,
                ]);
            }

            $this->logger->error('Bulk comic deletion failed.', ['exception' => $exception, 'comic_ids' => $comicIds]);
            return $this->json(['message' => 'Bulk deletion failed. No database records were deleted.'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return $this->json([
            'message' => sprintf('%d comic(s) deleted', count($comics)),
            'deletedComicIds' => $comicIds,
            'orphanedComicIds' => array_column($orphanedComics, 'id'),
        ]);
    }

    #[Route('/{id}', name: 'get', methods: ['GET'])]
    public function get(int $id, EntityManagerInterface $entityManager): JsonResponse
    {
        // Get the current user
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->json(['message' => 'User not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        $comic = $entityManager->getRepository(Comic::class)->find($id);
        if (!$comic) {
            return $this->json(['message' => 'Comic not found'], Response::HTTP_NOT_FOUND);
        }

        // Owner, administrator or accepted recipient — the voter is the only
        // place that answers this.
        if (!$this->isGranted(ComicVoter::VIEW, $comic)) {
            return $this->json(['message' => 'Access denied or comic not found'], Response::HTTP_FORBIDDEN); // Or HTTP_NOT_FOUND
        }

        return $this->json(['comic' => $this->comicSerializer->serialize($comic, $user)]);
    }

    #[Route('', name: 'create', methods: ['POST'])]
    public function create(
        Request $request,
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

        } catch (\Throwable $e) {
            $this->logger->warning('Comic upload failed.', ['user_id' => $user->getId(), 'exception' => $e]);

            // The exception message used to be echoed back. ComicService throws
            // its own vetted rejections ("Only CBZ files are allowed.") but the
            // same catch also sees filesystem and database errors, which name
            // server paths. The reasons a user can act on are enumerated here;
            // anything else is a server fault and reads as one.
            return $this->json([
                'message' => 'Upload failed. Check that the file is a valid CBZ within your storage quota.',
            ], Response::HTTP_BAD_REQUEST);
        }
    }

    #[Route('/{id}', name: 'update', methods: ['PUT', 'PATCH'])]
    public function update(
        int $id,
        Request $request,
        EntityManagerInterface $entityManager,
        AdminAuditService $auditService,
        ComicShareService $shareService
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

        // Editing is the owner's (and an administrator's). A recipient reads the
        // owner's comic and never changes it.
        if (!$this->isGranted(ComicVoter::EDIT, $comic)) {
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
            'explicitContent' => $comic->isExplicitContent(),
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

        // array_key_exists rather than isset, so unticking the box — an explicit
        // false — is a change the same way ticking it is. Guarded on is_array
        // because a valid JSON scalar body ("5") decodes without error and
        // array_key_exists would fatal on it.
        if (is_array($data) && array_key_exists('explicitContent', $data) && is_bool($data['explicitContent'])) {
            $comic->setExplicitContent($data['explicitContent']);
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
                /** @var TagRepository $tagRepository */
                $tagRepository = $entityManager->getRepository(Tag::class);
                $tag = $tagRepository->findAvailableByName($tagName, $tagOwner);
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

        // Newly explicit: everyone who is already reading it agreed to something
        // that was not classified 18+, so the gate closes on them until they say
        // otherwise. Done before the flush so the re-gate and the reclassification
        // land together — a crash between the two would leave recipients reading
        // a comic nobody had confirmed for.
        if (!$metadataBefore['explicitContent'] && $comic->isExplicitContent()) {
            $shareService->regateSharesForComic($comic);
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
                    'explicitContent' => $comic->isExplicitContent(),
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
        int $id,
        EntityManagerInterface $entityManager,
        ComicService $comicService,
        ComicShareService $shareService
    ): JsonResponse {
        $user = $this->getUser();
        if (!$user) {
            return $this->json(['message' => 'User not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        $comic = $entityManager->getRepository(Comic::class)->find($id);

        if (!$comic) {
            return $this->json(['message' => 'Comic not found'], Response::HTTP_NOT_FOUND);
        }

        if (!$this->isGranted(ComicVoter::DELETE, $comic)) {
            return $this->json(['message' => 'You do not have permission to delete this comic'], Response::HTTP_FORBIDDEN);
        }

        $quarantinedFiles = [];
        try {
            // Use a transaction to ensure all operations succeed or fail together
            $entityManager->beginTransaction();

            // Inside the same transaction as the removal: the access records and
            // the comic must not be able to disagree about whether it still
            // exists. Recipients keep a tombstone explaining the disappearance
            // instead of watching the comic vanish.
            $shareService->tombstoneSharesForComic($comic, ComicShare::REASON_OWNER_DELETED);

            // Keep files recoverable if the database transaction fails.
            $quarantinedFiles = $comicService->quarantineComicFiles($comic);

            // The entity removal will cascade to reading progress thanks to the relationship setup
            $entityManager->remove($comic);
            $entityManager->flush();

            $entityManager->commit();

            return $this->json(['message' => 'Comic deleted successfully']);
        } catch (\Throwable) {
            // Rollback the transaction if anything fails
            if ($entityManager->getConnection()->isTransactionActive()) {
                $entityManager->rollback();
            }

            try {
                $comicService->restoreQuarantinedFiles($quarantinedFiles);
            } catch (\Throwable) {
                return $this->json([
                    'message' => 'Failed to delete the comic and restore its files. An administrator must inspect the quarantine.',
                ], Response::HTTP_INTERNAL_SERVER_ERROR);
            }
            
            return $this->json(['message' => 'Failed to delete comic. No files were lost.'], Response::HTTP_INTERNAL_SERVER_ERROR);
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

        // Anyone who may read the comic may reset their own position in it —
        // progress is per user, so a recipient clearing theirs never touches
        // the owner's.
        if (!$this->isGranted(ComicVoter::VIEW, $comic)) {
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
        // Get the current user
        $user = $this->getUser();
        if (!$user) {
            return $this->json(['message' => 'User not authenticated'], Response::HTTP_UNAUTHORIZED);
        }
        
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

                // Validate chunk index
                if ($chunkIndex < 0 || $chunkIndex >= $metadata['totalChunks']) {
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
        ComicService $comicService
    ): JsonResponse {
        // Get the current user
        $user = $this->getUser();
        if (!$user instanceof User) {
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
            // influence filesystem path handling.
            $finalFilePath = $userChunkDir . '/' . self::ASSEMBLED_UPLOAD_FILENAME;
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

            // Clean up temp directory
            $this->cleanupTempDirectory($userChunkDir);

            return $this->json([
                'message' => 'Upload completed successfully',
                'comic' => $this->comicSerializer->serialize($comic, $user, false),
            ]);
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
    public function getPage(
        int $id,
        int $page,
        Request $request,
        EntityManagerInterface $entityManager,
        ComicService $comicService
    ): Response {
        // Get the current user
        $user = $this->getUser();
        if (!$user) {
            return $this->json(['message' => 'User not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        $comic = $entityManager->getRepository(Comic::class)->find($id);
        if (!$comic) {
            return $this->json(['message' => 'Comic not found'], Response::HTTP_NOT_FOUND);
        }

        // Pages are the comic itself, so they go through exactly the same check
        // as its metadata. Reported as missing rather than forbidden so probing
        // for ids learns nothing.
        if (!$this->isGranted(ComicVoter::VIEW, $comic)) {
            return $this->json(['message' => 'Comic not found'], Response::HTTP_NOT_FOUND);
        }

        // Validate page number
        if ($page < 1 || ($comic->getPageCount() !== null && $page > $comic->getPageCount())) {
            return $this->json(['message' => 'Invalid page number'], Response::HTTP_BAD_REQUEST);
        }

        // Always look for the comic in the user's directory first
        $owner = $comic->getOwner();
        $relativePath = basename((string) $comic->getFilePath());
        $userDirectory = $this->comicsDirectory . '/' . $owner->getId();
        $filePath = $userDirectory . '/' . $relativePath;

        // Fallback to old path if file doesn't exist in user directory
        if (!file_exists($filePath)) {
            $legacyPath = $this->comicsDirectory . '/' . $relativePath;

            // If still not found, return error
            if (!file_exists($legacyPath)) {
                return $this->json(['message' => 'Comic file not found'], Response::HTTP_NOT_FOUND);
            }

            // If found in the old location, copy it into the user's directory for future access
            if (@mkdir($userDirectory, 0775, true) || is_dir($userDirectory)) {
                if (@copy($legacyPath, $filePath)) {
                    $legacyPath = $filePath;
                }
            }

            if (!is_file($filePath)) {
                $this->logger->info('Serving comic page from the legacy storage location.', ['comic_id' => $comic->getId()]);
            }

            $filePath = $legacyPath;
        }

        // Validators taken from the archive rather than the extracted page, so a
        // revalidation can be answered without opening the CBZ at all. Reading a
        // comic is the app's hot path and every page used to be re-downloaded.
        $response = new Response();
        $response->setPrivate();
        $response->setMaxAge(self::PAGE_CACHE_SECONDS);
        $response->headers->set(AbstractSessionListener::NO_AUTO_CACHE_CONTROL_HEADER, 'true');

        $modifiedAt = @filemtime($filePath);
        if ($modifiedAt !== false) {
            $response->setLastModified(new \DateTimeImmutable('@' . $modifiedAt));
            $response->setEtag(hash('sha256', $filePath . '|' . $modifiedAt . '|' . @filesize($filePath) . '|' . $page));

            if ($response->isNotModified($request)) {
                return $response;
            }
        }

        // The page list comes from ComicService, cached against the archive, so
        // a reading session scans it once rather than once per page — and so
        // the numbering here is the same numbering the stored page count was
        // derived from. This used to be a second, unfiltered copy of that loop,
        // which counted __MACOSX resource forks as pages and shifted every page
        // after one of them.
        try {
            $imageFiles = $comicService->getPageIndex($filePath);
        } catch (\Throwable $exception) {
            $this->logger->error('Failed to read a comic archive.', [
                'comic_id' => $comic->getId(),
                'exception' => $exception,
            ]);

            return $this->json(['message' => 'Failed to open comic file'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        // Check if requested page exists
        if (!isset($imageFiles[$page - 1])) {
            return $this->json(['message' => 'Page not found'], Response::HTTP_NOT_FOUND);
        }

        $zip = new ZipArchive();
        if ($zip->open($filePath) !== true) {
            return $this->json(['message' => 'Failed to open comic file'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        // Get page image
        $pageImage = $zip->getFromName($imageFiles[$page - 1]);
        $zip->close();

        if ($pageImage === false) {
            return $this->json(['message' => 'Failed to extract page image'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        // Return image, keeping the caching policy set up before the archive was
        // opened.
        $response->setContent($pageImage);
        $extension = strtolower(pathinfo($imageFiles[$page - 1], PATHINFO_EXTENSION));
        $mimeType = $this->getMimeTypeForExtension($extension);
        $response->headers->set('Content-Type', $mimeType);
        return $response;
    }

    /**
     * Download the original CBZ.
     *
     * Owners only, deliberately: this is the backup path for your own library.
     * A shared comic is read through the reader, and handing a recipient the
     * archive would put a second permanent copy outside the owner's control —
     * which is exactly what the sharing model exists to avoid. Administrators
     * are not exempted either; moderating a library is not a reason to take a
     * copy of somebody's files.
     */
    #[Route('/{id}/download', name: 'download', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function download(int $id, EntityManagerInterface $entityManager, ComicService $comicService): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->json(['message' => 'User not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        $comic = $entityManager->getRepository(Comic::class)->find($id);
        if (!$comic) {
            return $this->json(['message' => 'Comic not found'], Response::HTTP_NOT_FOUND);
        }

        if ($comic->getOwner()?->getId() !== $user->getId()) {
            return $this->json(
                ['message' => 'Only the owner of a comic can download its file.'],
                Response::HTTP_FORBIDDEN
            );
        }

        $archivePath = $comicService->locateComicArchive($comic);
        if ($archivePath === null) {
            return $this->json(['message' => 'Comic file not found'], Response::HTTP_NOT_FOUND);
        }

        $response = new BinaryFileResponse($archivePath);
        $response->setPrivate();
        // The stored filename carries a uniqid, so the download is named after
        // the comic instead. setContentDisposition escapes it and supplies an
        // ASCII fallback for titles that are not.
        $response->setContentDisposition(
            ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            $this->downloadFilename($comic)
        );
        $response->headers->set('Content-Type', 'application/vnd.comicbook+zip');
        $response->headers->set(AbstractSessionListener::NO_AUTO_CACHE_CONTROL_HEADER, 'true');

        return $response;
    }

    private function downloadFilename(Comic $comic): string
    {
        $title = trim((string) $comic->getTitle());
        // Reserved and path-significant characters are dropped rather than
        // escaped, so nothing in a title can steer where the file lands.
        $safeTitle = trim((string) preg_replace('/[\x00-\x1F\/\\\\:*?"<>|]+/u', ' ', $title));
        $safeTitle = (string) preg_replace('/\s+/u', ' ', $safeTitle);

        if ($safeTitle === '') {
            $safeTitle = 'comic-' . $comic->getId();
        }

        return mb_substr($safeTitle, 0, 100) . '.cbz';
    }

    #[Route('/{id}/progress', name: 'update_progress', methods: ['POST'])]
    public function updateReadingProgressEndpoint(
        int $id, 
        Request $request, 
        EntityManagerInterface $entityManager
    ): JsonResponse {
        // Get the current user
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->json(['message' => 'User not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        $comic = $entityManager->getRepository(Comic::class)->find($id);
        if (!$comic) {
            return $this->json(['message' => 'Comic not found'], Response::HTTP_NOT_FOUND);
        }

        if (!$this->isGranted(ComicVoter::VIEW, $comic)) {
            return $this->json(['message' => 'Comic not found'], Response::HTTP_NOT_FOUND);
        }

        // An administrator reading somebody else's library is inspecting it, not
        // reading it, so their position is not stored. A recipient with an
        // accepted share genuinely is reading, and keeps their own position —
        // which is why the share is checked before the admin role.
        $isRecipient = $this->shareRepository->findAccessFor($user, $comic) !== null;
        $isAdminReadingAnotherUsersComic = !$isRecipient
            && $comic->getOwner()?->getId() !== $user->getId()
            && in_array('ROLE_ADMIN', $user->getRoles(), true);

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

        // Optional so older clients keep working; when present it orders saves
        // that the reader fired in quick succession and that may arrive swapped.
        // Revisions start at 1: 0 is the stored value for progress that predates
        // this, so a save numbered 0 could never win the comparison below.
        $revision = null;
        if (isset($data['revision'])) {
            if (!is_numeric($data['revision']) || $data['revision'] < 1) {
                return $this->json(['message' => 'Invalid revision'], Response::HTTP_BAD_REQUEST);
            }
            $revision = (int) $data['revision'];
        }

        if ($isAdminReadingAnotherUsersComic) {
            return $this->json([
                'message' => 'Admin read-only progress ignored',
                'progress' => [
                    'currentPage' => $currentPage,
                    'lastReadAt' => (new \DateTimeImmutable())->format('c'),
                    'completed' => $completed,
                    'revision' => $revision ?? 0,
                ],
            ]);
        }

        // Update reading progress
        $progress = $this->updateReadingProgress($user, $comic, $currentPage, $entityManager, $completed, $revision);

        return $this->json([
            'message' => 'Reading progress updated',
            'progress' => [
                'currentPage' => $progress->getCurrentPage(),
                'lastReadAt' => $progress->getLastReadAt()->format('c'),
                'completed' => $progress->isCompleted(),
                'revision' => $progress->getRevision(),
            ]
        ]);
    }



    /**
     * Update reading progress for a user and comic.
     *
     * @param bool $isRetry set once the first attempt lost the race to create
     *                      the row; stops a pathological loop
     */
    private function updateReadingProgress(
        User $user,
        Comic $comic,
        int $currentPage,
        EntityManagerInterface $entityManager,
        bool $completed = false,
        ?int $revision = null,
        bool $isRetry = false
    ): ComicReadingProgress {
        // Get existing progress or create new one
        $progress = $entityManager->getRepository(ComicReadingProgress::class)
            ->findOneBy(['comic' => $comic, 'user' => $user]);

        // Mark as completed if specified or if on the last page. Completion is
        // never taken back here, only granted.
        $isCompleted = $completed || ($comic->getPageCount() !== null && $currentPage >= $comic->getPageCount());

        if ($progress && $revision !== null) {
            // Conditional update so the comparison and the write are a single
            // statement: a save that lost the race leaves the stored page alone.
            $applied = (int) $entityManager->createQuery(
                'UPDATE ' . ComicReadingProgress::class . ' p
                 SET p.currentPage = :currentPage, p.completed = :completed, p.lastReadAt = :lastReadAt, p.revision = :revision
                 WHERE p.id = :id AND p.revision < :revision'
            )
                ->setParameter('currentPage', $currentPage)
                ->setParameter('completed', $isCompleted || $progress->isCompleted())
                ->setParameter('lastReadAt', new \DateTimeImmutable())
                ->setParameter('revision', $revision)
                ->setParameter('id', $progress->getId())
                ->execute();

            // A DQL update bypasses the unit of work, so the managed entity is
            // stale either way: refreshed, it reports whichever save won.
            $entityManager->refresh($progress);

            if ($applied === 0) {
                $this->logger->debug('Ignored a superseded reading progress save.', [
                    'comic_id' => $comic->getId(),
                    'revision' => $revision,
                ]);
            }

            return $progress;
        }

        $isNew = $progress === null;
        if ($isNew) {
            $progress = new ComicReadingProgress();
            $progress->setUser($user);
            $progress->setComic($comic);
            $entityManager->persist($progress);
        }

        // Update progress
        $progress->setCurrentPage($currentPage);
        if ($revision !== null) {
            $progress->setRevision($revision);
        }

        if ($isCompleted) {
            $progress->setCompleted(true);
        }

        try {
            $entityManager->flush();
        } catch (UniqueConstraintViolationException $exception) {
            // Somebody else created the row between the lookup above and this
            // insert — a second tab, or another device. The database refused the
            // duplicate, which is the point of the constraint; the save itself
            // is still perfectly valid, so it is applied to the row that won
            // rather than reported to the reader as a failure.
            if (!$isNew || $isRetry) {
                throw $exception;
            }

            // A failed flush closes the entity manager, so the retry needs a
            // fresh one. The comic and user are re-fetched through it for the
            // same reason: entities from a closed manager are not managed.
            $this->logger->debug('Reading progress row was created concurrently; applying the save to it.', [
                'comic_id' => $comic->getId(),
            ]);
            $this->managerRegistry->resetManager();
            $freshManager = $this->managerRegistry->getManager();
            $freshUser = $freshManager->find(User::class, $user->getId());
            $freshComic = $freshManager->find(Comic::class, $comic->getId());

            // Losing the race is recoverable; losing the comic is not. If the
            // owner deleted it in the same instant, there is nothing left to
            // record a position against.
            if (!$freshManager instanceof EntityManagerInterface
                || !$freshUser instanceof User
                || !$freshComic instanceof Comic
            ) {
                throw $exception;
            }

            return $this->updateReadingProgress(
                $freshUser,
                $freshComic,
                $currentPage,
                $freshManager,
                $completed,
                $revision,
                true
            );
        }

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

    /** @return list<int> */
    private function normaliseBulkComicIds(mixed $comicIds): array
    {
        if (!is_array($comicIds) || count($comicIds) > 200) {
            return [];
        }

        $normalised = [];
        foreach ($comicIds as $comicId) {
            if (!is_int($comicId) && !(is_string($comicId) && ctype_digit($comicId))) {
                return [];
            }

            $comicId = (int) $comicId;
            if ($comicId <= 0) {
                return [];
            }
            $normalised[$comicId] = $comicId;
        }

        return array_values($normalised);
    }

    /**
     * @return list<array{id: int, changes: array<string, mixed>}>
     */
    private function normaliseComicUpdates(mixed $updates): array
    {
        if (!is_array($updates) || $updates === [] || count($updates) > 200) {
            return [];
        }

        $normalised = [];
        foreach ($updates as $update) {
            if (!is_array($update)) {
                return [];
            }

            $ids = $this->normaliseBulkComicIds([$update['id'] ?? null]);
            $changes = $update['changes'] ?? null;
            if (count($ids) !== 1 || !is_array($changes) || $changes === []) {
                return [];
            }

            $allowedFields = ['title', 'author', 'publisher', 'description', 'tags', 'addTags', 'explicitContent'];
            if (array_diff(array_keys($changes), $allowedFields) !== []) {
                return [];
            }

            // A classification is a yes or a no. Anything else — "true", 1, null
            // — is a client that has not decided, and guessing which way it
            // meant is not this endpoint's job.
            if (array_key_exists('explicitContent', $changes) && !is_bool($changes['explicitContent'])) {
                return [];
            }

            if (array_key_exists('title', $changes) && (
                !is_string($changes['title'])
                || trim($changes['title']) === ''
                || mb_strlen(trim($changes['title'])) > 255
            )) {
                return [];
            }
            if (array_key_exists('title', $changes)) {
                $changes['title'] = trim($changes['title']);
            }
            foreach (['author', 'publisher', 'description'] as $field) {
                if (array_key_exists($field, $changes) && !is_string($changes[$field]) && $changes[$field] !== null) {
                    return [];
                }
            }
            foreach (['author', 'publisher'] as $field) {
                if (is_string($changes[$field] ?? null) && mb_strlen($changes[$field]) > 255) {
                    return [];
                }
            }
            foreach (['tags', 'addTags'] as $field) {
                if (!array_key_exists($field, $changes)) {
                    continue;
                }
                $tagNames = $this->normaliseTagNames($changes[$field]);
                if ($tagNames === null) {
                    return [];
                }
                $changes[$field] = $tagNames;
            }

            $normalised[$ids[0]] = ['id' => $ids[0], 'changes' => $changes];
        }

        return array_values($normalised);
    }

    /** @return list<string>|null */
    private function normaliseTagNames(mixed $tags): ?array
    {
        if (!is_array($tags) || count($tags) > 50) {
            return null;
        }

        $normalised = [];
        foreach ($tags as $tag) {
            $tagName = is_array($tag) ? ($tag['name'] ?? null) : $tag;
            if (!is_string($tagName)) {
                return null;
            }
            $tagName = trim($tagName);
            if ($tagName === '' || mb_strlen($tagName) > 50) {
                return null;
            }
            $normalised[mb_strtolower($tagName)] = $tagName;
        }

        return array_values($normalised);
    }

    /**
     * Serve an image the browser is allowed to keep.
     *
     * Covers are private: they are reachable only through this authenticated
     * endpoint, so the policy stops at the user's own browser and never lets a
     * shared proxy hand one user's cover to another.
     *
     * Within that one browser the entry is still keyed by the session cookie,
     * so an admin who signs out and another account signs in cannot be served
     * the previous account's cover from cache. The session cookie is the only
     * credential this endpoint authenticates with, so it is the whole of Vary.
     */
    private function cacheableImageResponse(
        string $absolutePath,
        Request $request,
        int $maxAge,
        bool $immutable
    ): BinaryFileResponse {
        $response = new BinaryFileResponse($absolutePath);
        $response->setAutoLastModified();
        $response->setAutoEtag();
        $response->setPrivate();
        $response->setMaxAge($maxAge);
        $response->setVary('Cookie');
        if ($immutable) {
            $response->setImmutable();
        }

        // Symfony disables caching on every response whose request touched the
        // session, which is every authenticated request. Opting out is what
        // lets the policy above reach the browser at all.
        $response->headers->set(AbstractSessionListener::NO_AUTO_CACHE_CONTROL_HEADER, 'true');

        // Turns a revalidation into an empty 304 instead of resending the file.
        $response->isNotModified($request);

        return $response;
    }

    #[Route('/cover/{userId}/{comicId}/{filename}', name: 'cover_image', methods: ['GET'])]
    public function getCoverImage(
        int $userId,
        int $comicId,
        string $filename,
        Request $request,
        EntityManagerInterface $entityManager
    ): Response {
        /** @var \App\Entity\User|null $currentUser */
        $currentUser = $this->getUser();
        if (!$currentUser) {
            return $this->json(['message' => 'Not authenticated.'], Response::HTTP_UNAUTHORIZED);
        }

        // The owner id in the URL only has to agree with the comic; whether this
        // request may see the cover is the voter's decision, so a recipient's
        // cover request is not rejected for pointing at somebody else's id.
        $owner = $entityManager->getRepository(User::class)->find($userId);
        $comic = $owner ? $entityManager->getRepository(Comic::class)->findOneBy(['id' => $comicId, 'owner' => $owner]) : null;
        if (!$comic) {
            return $this->json(['message' => 'Comic not found or not owned by user.'], Response::HTTP_NOT_FOUND);
        }

        if (!$this->isGranted(ComicVoter::VIEW, $comic)) {
            // Worth logging: it is either probing or a misconfiguration.
            $this->logger->warning('Forbidden cover access attempt.', [
                'current_user_id' => $currentUser->getId(),
                'target_user_id' => $userId,
            ]);
            return $this->json(['message' => 'Forbidden.'], Response::HTTP_FORBIDDEN);
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
                return $this->cacheableImageResponse(
                    $placeholderPath,
                    $request,
                    self::COVER_PLACEHOLDER_CACHE_SECONDS,
                    false
                );
            }

            return $this->json(['message' => 'Cover image file not found on server.'], Response::HTTP_NOT_FOUND);
        }

        // BinaryFileResponse handles Content-Type, Content-Length and range
        // requests; the helper adds the caching policy on top so returning to
        // the library re-displays covers from the browser cache.
        return $this->cacheableImageResponse($absolutePath, $request, self::COVER_CACHE_SECONDS, true);
    }
}
