<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Comic;
use App\Entity\ComicReadingProgress;
use App\Entity\ComicShare;
use App\Entity\Tag;
use App\Entity\User;
use App\Repository\ComicRepository;
use App\Repository\ComicShareRepository;
use App\Repository\TagRepository;
use App\Security\ComicAccess;
use App\Security\Voter\ComicVoter;
use App\Service\AdminAuditService;
use App\Service\ApiRateLimiter;
use App\Service\MetadataProviderRegistry;
use App\Service\ComicSerializer;
use App\Service\ComicShareService;
use App\Service\ComicLibraryQueryService;
use App\Service\LibraryFolderService;
use App\Metadata\StructuredMetadataInput;
use App\Service\ComicService;
use App\Service\Pagination\PaginationRequest;
use App\Service\SecurityAuditLogger;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * A comic as a library entry: listing, reading position, and the fields its
 * owner may change.
 *
 * The routes that receive an upload, talk to a metadata provider, or serve the
 * actual bytes are their own controllers — {@see ComicUploadController},
 * {@see ComicMetadataController} and {@see ComicPageController}. They share
 * this prefix, and nothing else: each was pulling a different set of
 * collaborators into one constructor, and the file had reached a size where
 * the only way to find a route was to search for it.
 */
#[Route('/api/comics', name: 'api_comics_')]
class ComicController extends AbstractController
{
    use RequiresAuthenticatedUser;

    /**
     * How many comics one request may remove before an administrator is told.
     *
     * Above a handful, a bulk delete stops looking like housekeeping. It is not
     * refused — it is the owner's library — but it is the shape a compromised
     * account leaves behind, and it is worth somebody knowing while the files
     * are still in quarantine.
     */
    private const BULK_DELETE_ALERT_THRESHOLD = 25;

    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly ComicSerializer $comicSerializer,
        private readonly ComicShareRepository $shareRepository,
        private readonly ManagerRegistry $managerRegistry,
        private readonly ComicAccess $comicAccess
    ) {
    }

    #[Route('', name: 'list', methods: ['GET'])]
    public function list(
        Request $request,
        EntityManagerInterface $entityManager,
        ApiRateLimiter $rateLimiter,
        ComicLibraryQueryService $libraryQuery,
        LibraryFolderService $folderService
    ): JsonResponse
    {
        $user = $this->requireUser();

        // Get search parameters
        $search = $request->query->get('search');
        $tagsParam = $request->query->get('tags');
        $tagNames = $tagsParam
            ? array_values(array_unique(array_filter(array_map('trim', explode(',', $tagsParam)))))
            : [];
        
        // Check if we're in admin context - only consider this parameter if user is an admin
        $adminContext = $request->query->get('adminContext') === 'true' && $user->isAdmin();

        // Apply rate limiting only when search or tags parameters are present.
        // The admin table is exempt: its search is debounced but server-side, so
        // ten keystrokes a minute is a normal amount of typing, and the query it
        // runs is bounded by the page size.
        if (!$adminContext && ($search || $tagsParam)) {
            $rateLimitResponse = $rateLimiter->limit($request, 'comic_search', 'user:' . $user->getId());
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

        $folderScope = null;
        if ($request->query->has('folder')) {
            $rawFolder = (string) $request->query->get('folder');
            if ($rawFolder === 'root') {
                $folderScope = 'root';
            } elseif (ctype_digit($rawFolder) && (int) $rawFolder > 0) {
                $folderScope = $folderService->findOwned($user, (int) $rawFolder);
                if ($folderScope === null) {
                    return $this->json(['message' => 'Folder not found.'], Response::HTTP_NOT_FOUND);
                }
            } else {
                return $this->json(['message' => 'Invalid folder.'], Response::HTTP_BAD_REQUEST);
            }
        }

        $comics = $libraryQuery->findVisibleLibrary(
            $user,
            (string) $request->query->get('ownership', 'all'),
            is_string($search) ? $search : null,
            $tagNames,
            $folderScope
        );

        return $this->json([
            'comics' => $this->comicSerializer->serializeMany($comics, $user, $adminContext),
        ]);
    }

    #[Route('', name: 'batch_update', methods: ['PATCH'])]
    public function batchUpdate(
        Request $request,
        EntityManagerInterface $entityManager,
        ComicShareService $shareService,
        SecurityAuditLogger $securityLogger,
        MetadataProviderRegistry $providers
    ): JsonResponse {
        $user = $this->requireUser();

        $data = \App\Http\JsonRequestDecoder::decode($request);
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
        // A tag belongs to the library it is being put in, not to whoever is
        // doing the putting. This route only ever loads the caller's own comics,
        // so the two are the same today; naming the owner anyway keeps the rule
        // true if that scoping is ever relaxed, and matches what the
        // single-comic route already does.
        $getTag = function (string $tagName, User $owner) use (&$tagsByName, $entityManager): Tag {
            $tagKey = $owner->getId().'|'.mb_strtolower($tagName);
            if (isset($tagsByName[$tagKey])) {
                return $tagsByName[$tagKey];
            }

            /** @var TagRepository $tagRepository */
            $tagRepository = $entityManager->getRepository(Tag::class);
            $tag = $tagRepository->findAvailableByName($tagName, $owner);
            if (!$tag) {
                $tag = (new Tag())->setName($tagName)->setCreator($owner);
                $entityManager->persist($tag);
            }

            return $tagsByName[$tagKey] = $tag;
        };

        // Collected during the loop and written after the flush, so a batch that
        // fails to save does not leave audit entries claiming it did.
        $reclassifications = [];

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

            // The edit dialog saves through this route, so the structured
            // fields have to be accepted here as well as on the single-comic
            // update — otherwise an accepted suggestion is staged, saved, and
            // silently dropped on the way.
            $structured = new StructuredMetadataInput($this->providerKeys($providers));
            if (!$structured->applyTo($changes, $comic)) {
                return $this->json(
                    ['message' => implode(' ', $structured->errors())],
                    Response::HTTP_UNPROCESSABLE_ENTITY
                );
            }

            if (array_key_exists('explicitContent', $changes)) {
                $wasExplicit = $comic->isExplicitContent();
                $comic->setExplicitContent($changes['explicitContent']);

                // Same rule as the single-comic update: newly explicit means
                // every live share loses its confirmation and the recipient has
                // to make the declaration again.
                $regated = 0;
                if (!$wasExplicit && $changes['explicitContent']) {
                    $regated = $shareService->regateSharesForComic($comic);
                }

                if ($wasExplicit !== $comic->isExplicitContent()) {
                    $reclassifications[] = [
                        'actor_user_id' => $user->getId(),
                        'target_type' => 'comic',
                        'target_id' => $comic->getId(),
                        'comic_id' => $comic->getId(),
                        'owner_user_id' => $comic->getOwner()?->getId(),
                        'explicit_before' => $wasExplicit,
                        'explicit_after' => $comic->isExplicitContent(),
                        'shares_regated' => $regated,
                    ];
                }
            }

            if (array_key_exists('tags', $changes)) {
                foreach ($comic->getTags()->toArray() as $tag) {
                    $comic->removeTag($tag);
                }
                foreach ($changes['tags'] as $tagName) {
                    $comic->addTag($getTag($tagName, $comic->getOwner() ?? $user));
                }
            }

            foreach ($changes['addTags'] ?? [] as $tagName) {
                $comic->addTag($getTag($tagName, $comic->getOwner() ?? $user));
            }
        }
        $entityManager->flush();

        foreach ($reclassifications as $reclassification) {
            $securityLogger->audit(
                SecurityAuditLogger::COMIC_EXPLICIT_CLASSIFICATION_CHANGED,
                $reclassification
            );
        }

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
        ComicShareService $shareService,
        SecurityAuditLogger $securityLogger
    ): JsonResponse {
        $user = $this->requireUser();

        $data = \App\Http\JsonRequestDecoder::decode($request);
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
            if (!$comicService->comicSourceExists($comic)) {
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
        $tombstonedShares = 0;
        try {
            $entityManager->beginTransaction();
            foreach ($comics as $comic) {
                // Same contract as the single delete: recipients are told why
                // the comic went away, in the transaction that removes it.
                $tombstonedShares += $shareService->tombstoneSharesForComic($comic, ComicShare::REASON_OWNER_DELETED);
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

            $securityLogger->critical(
                SecurityAuditLogger::DATA_INTEGRITY_FAILURE,
                [
                    'actor_user_id' => $user->getId(),
                    'target_type' => 'comic',
                    'operation' => 'comic_bulk_delete',
                    'count' => count($comicIds),
                    'reason' => 'bulk deletion rolled back',
                ],
                SecurityAuditLogger::RESULT_FAILED,
                'user:' . $user->getId()
            );

            return $this->json(['message' => 'Bulk deletion failed. No database records were deleted.'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        // After the commit, so the record describes a deletion that actually
        // landed — and it carries the tombstone count, because "how many people
        // lost access" is the part of a deletion nobody else records.
        $securityLogger->audit(SecurityAuditLogger::COMICS_BULK_DELETED, [
            'actor_user_id' => $user->getId(),
            'target_type' => 'comic',
            'count' => count($comics),
            'comic_ids' => $comicIds,
            'orphaned_entries' => count($orphanedComics),
            'shares_tombstoned' => $tombstonedShares,
        ]);

        // A large sweep is either somebody clearing out their own library or an
        // account somebody else is now in control of. The threshold makes the
        // second one visible without an email for every tidy-up.
        if (count($comics) >= self::BULK_DELETE_ALERT_THRESHOLD) {
            $securityLogger->critical(
                SecurityAuditLogger::COMIC_BULK_DELETE_UNUSUAL,
                [
                    'actor_user_id' => $user->getId(),
                    'target_type' => 'comic',
                    'count' => count($comics),
                    'reason' => 'bulk deletion above the alert threshold',
                ],
                SecurityAuditLogger::RESULT_SUCCESS,
                'user:' . $user->getId()
            );
        }

        return $this->json([
            'message' => sprintf('%d comic(s) deleted', count($comics)),
            'deletedComicIds' => $comicIds,
            'orphanedComicIds' => array_column($orphanedComics, 'id'),
        ]);
    }

    #[Route('/{id}', name: 'get', methods: ['GET'])]
    public function get(int $id): JsonResponse
    {
        $user = $this->requireUser();

        // Owner, administrator or accepted recipient — the voter is the only
        // place that answers this.
        $comic = $this->comicAccess->requireComic($id, ComicVoter::VIEW);

        return $this->json(['comic' => $this->comicSerializer->serialize($comic, $user)]);
    }

    /**
     * The providers this server actually has, so a client cannot record a
     * comic as matched to something that can never be looked up again.
     *
     * @return list<string>
     */
    private function providerKeys(MetadataProviderRegistry $providers): array
    {
        return array_map(
            static fn (\App\Metadata\Provider\MetadataProviderInterface $provider): string => $provider->key(),
            $providers->all()
        );
    }

    #[Route('', name: 'create', methods: ['POST'])]
    public function create(
        Request $request,
        ComicService $comicService,
        LibraryFolderService $folderService
    ): JsonResponse {
        $user = $this->requireUser();

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
        $folderId = $request->request->get('folderId');

        // Validate title
        if (!$title) {
            return $this->json(['message' => 'Title is required'], Response::HTTP_BAD_REQUEST);
        }
        if ($folderId !== null && $folderId !== '') {
            if (!ctype_digit((string) $folderId) || (int) $folderId < 1 || $folderService->findOwned($user, (int) $folderId) === null) {
                return $this->json(['message' => 'Folder not found.'], Response::HTTP_BAD_REQUEST);
            }
            $folderId = (int) $folderId;
        } else {
            $folderId = null;
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
            $folderService->placeUploadedComic($user, $comic, $folderId);

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
                'message' => 'Upload failed. Check that the file is a valid enabled comic format within your storage quota.',
            ], Response::HTTP_BAD_REQUEST);
        }
    }

    #[Route('/{id}', name: 'update', methods: ['PUT', 'PATCH'])]
    public function update(
        int $id,
        Request $request,
        EntityManagerInterface $entityManager,
        AdminAuditService $auditService,
        ComicShareService $shareService,
        SecurityAuditLogger $securityLogger,
        MetadataProviderRegistry $providers
    ): JsonResponse {
        $user = $this->requireUser();

        // Editing is the owner's (and an administrator's). A recipient reads the
        // owner's comic and never changes it.
        $comic = $this->comicAccess->requireComic($id, ComicVoter::EDIT);

        // Get data from request
        $data = \App\Http\JsonRequestDecoder::decode($request);

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

        // Accepting a suggestion is an ordinary edit, so it arrives here rather
        // than through a route of its own and is authorised the same way.
        if (is_array($data)) {
            $structured = new StructuredMetadataInput($this->providerKeys($providers));
            if (!$structured->applyTo($data, $comic)) {
                return $this->json(
                    ['message' => implode(' ', $structured->errors())],
                    Response::HTTP_UNPROCESSABLE_ENTITY
                );
            }
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
        $regated = 0;
        if (!$metadataBefore['explicitContent'] && $comic->isExplicitContent()) {
            $regated = $shareService->regateSharesForComic($comic);
        }

        if ($user->isAdmin() && $comic->getOwner()?->getId() !== $user->getId()) {
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

        // Both directions are auditable. Turning the flag off is the one that
        // opens something up, and it is the change nobody would think to look
        // for unless it were recorded. No title, no tags, no cover — the record
        // is the classification and the shares it moved.
        if ($metadataBefore['explicitContent'] !== $comic->isExplicitContent()) {
            $securityLogger->audit(SecurityAuditLogger::COMIC_EXPLICIT_CLASSIFICATION_CHANGED, [
                'actor_user_id' => $user->getId(),
                'target_type' => 'comic',
                'target_id' => $comic->getId(),
                'comic_id' => $comic->getId(),
                'owner_user_id' => $comic->getOwner()?->getId(),
                'explicit_before' => $metadataBefore['explicitContent'],
                'explicit_after' => $comic->isExplicitContent(),
                'shares_regated' => $regated,
            ]);
        }

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
        ComicShareService $shareService,
        SecurityAuditLogger $securityLogger
    ): JsonResponse {
        $user = $this->requireUser();

        $comic = $this->comicAccess->requireComic($id, ComicVoter::DELETE);

        $comicId = $comic->getId();
        $ownerId = $comic->getOwner()?->getId();
        $liveShares = $shareService->countLiveShares($comic);

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

            $securityLogger->audit(SecurityAuditLogger::COMIC_DELETED, [
                'actor_user_id' => $user->getId(),
                'target_type' => 'comic',
                'target_id' => $comicId,
                'comic_id' => $comicId,
                'owner_user_id' => $ownerId,
                'shares_tombstoned' => $liveShares,
            ]);

            return $this->json(['message' => 'Comic deleted successfully']);
        } catch (\Throwable) {
            // Rollback the transaction if anything fails
            if ($entityManager->getConnection()->isTransactionActive()) {
                $entityManager->rollback();
            }

            try {
                $comicService->restoreQuarantinedFiles($quarantinedFiles);
            } catch (\Throwable) {
                // The row survived and the file did not come back. That is the
                // library and the disk disagreeing, which nothing in the normal
                // flow will notice or repair, so an administrator is told at
                // once rather than finding out from a reader.
                $securityLogger->critical(
                    SecurityAuditLogger::DATA_INTEGRITY_FAILURE,
                    [
                        'actor_user_id' => $user->getId(),
                        'target_type' => 'comic',
                        'target_id' => $comicId,
                        'operation' => 'comic_delete_rollback',
                        'reason' => 'quarantined files could not be restored after a failed deletion',
                    ],
                    SecurityAuditLogger::RESULT_FAILED,
                    'storage'
                );

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
        $user = $this->requireUser();

        // Anyone who may read the comic may reset their own position in it —
        // progress is per user, so a recipient clearing theirs never touches
        // the owner's.
        $comic = $this->comicAccess->requireComic($id, ComicVoter::VIEW);

        // Find and remove reading progress
        $readingProgress = $entityManager->getRepository(ComicReadingProgress::class)
            ->findOneBy(['comic' => $comic, 'user' => $user]);

        if ($readingProgress) {
            $entityManager->remove($readingProgress);
            $entityManager->flush();
        }

        return $this->json(['message' => 'Reading progress reset successfully']);
    }
    
    
    

    

    #[Route('/{id}/progress', name: 'update_progress', methods: ['POST'])]
    public function updateReadingProgressEndpoint(
        int $id, 
        Request $request, 
        EntityManagerInterface $entityManager
    ): JsonResponse {
        $user = $this->requireUser();

        $comic = $this->comicAccess->requireComic($id, ComicVoter::VIEW);

        // An administrator reading somebody else's library is inspecting it, not
        // reading it, so their position is not stored. A recipient with an
        // accepted share genuinely is reading, and keeps their own position —
        // which is why the share is checked before the admin role.
        $isRecipient = $this->shareRepository->findAccessFor($user, $comic) !== null;
        $isAdminReadingAnotherUsersComic = !$isRecipient
            && $comic->getOwner()?->getId() !== $user->getId()
            && $user->isAdmin();

        // Get data from request
        $data = \App\Http\JsonRequestDecoder::decode($request);

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

            $allowedFields = [
                'title', 'author', 'publisher', 'description', 'tags', 'addTags', 'explicitContent',
                // Structured metadata, so accepting a suggestion in the edit
                // dialog survives the save. Their values are validated by
                // StructuredMetadataInput once the comic is in hand; this list
                // only decides which keys the endpoint will look at.
                'series', 'issueNumber', 'issueCount', 'volume', 'publishedAt', 'languageCode', 'ageRating',
                // Reviewed credits, and which external record was accepted. An
                // unknown key here rejects the whole batch rather than being
                // dropped, so a field the dialog can send has to be listed.
                'creators', 'metadataProvider', 'metadataExternalId',
            ];
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

}
