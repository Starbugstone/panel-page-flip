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
use App\Security\Voter\ComicVoter;
use App\Service\AdminAuditService;
use App\Service\ApiRateLimiter;
use App\Service\ComicMetadataSuggestionService;
use App\Service\ComicTagSuggestionService;
use App\Metadata\Provider\ProviderQuery;
use App\Service\MetadataProviderRegistry;
use App\Service\ComicSerializer;
use App\Service\PageDerivativeService;
use App\Service\ComicShareService;
use App\Service\ComicUploadFilenameValidator;
use App\Service\ComicFormatService;
use App\Service\ComicLibraryQueryService;
use App\Service\LibraryFolderService;
use App\Enum\ComicSourceType;
use App\Enum\PageVariant;
use App\Metadata\StructuredMetadataInput;
use App\Service\ComicService;
use App\Service\Pagination\PaginationRequest;
use App\Service\SecurityAuditLogger;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpKernel\EventListener\AbstractSessionListener;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/comics', name: 'api_comics_')]
class ComicController extends AbstractController
{
    use RequiresAuthenticatedUser;

    private const FILE_ID_REGEX = '/^[A-Za-z0-9\-]{8,64}$/';

    /**
     * How many comics one request may remove before an administrator is told.
     *
     * Above a handful, a bulk delete stops looking like housekeeping. It is not
     * refused — it is the owner's library — but it is the shape a compromised
     * account leaves behind, and it is worth somebody knowing while the files
     * are still in quarantine.
     */
    private const BULK_DELETE_ALERT_THRESHOLD = 25;

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
        private readonly ComicUploadFilenameValidator $uploadFilenameValidator,
        private readonly ComicFormatService $comicFormatService,
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
        $validated = $this->uploadFilenameValidator->validate($filename);
        $type = ComicSourceType::fromFilename($validated);
        if (!$this->comicFormatService->isEnabled($type)) {
            throw new BadRequestHttpException(sprintf('%s uploads are not enabled.', strtoupper($type->value)));
        }
        return $validated;
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
        $adminContext = $request->query->get('adminContext') === 'true' && in_array('ROLE_ADMIN', $user->getRoles());

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
    public function get(int $id, EntityManagerInterface $entityManager): JsonResponse
    {
        $user = $this->requireUser();

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

    /**
     * What could be filled in about this comic, and where each proposal came
     * from. Read-only by design: applying a suggestion is an edit, and goes
     * through the ordinary update route so it is authorised the same way.
     */
    #[Route('/{id}/metadata-suggestions', name: 'metadata_suggestions', methods: ['GET'])]
    public function metadataSuggestions(
        int $id,
        EntityManagerInterface $entityManager,
        ComicMetadataSuggestionService $suggestions,
        ComicTagSuggestionService $tagSuggestions,
        MetadataProviderRegistry $providers
    ): JsonResponse {
        $user = $this->requireUser();

        $comic = $entityManager->getRepository(Comic::class)->find($id);
        if (!$comic) {
            return $this->json(['message' => 'Comic not found'], Response::HTTP_NOT_FOUND);
        }

        // Suggestions describe the comic, so seeing them needs the same right as
        // seeing the comic; acting on them needs the right to edit it.
        if (!$this->isGranted(ComicVoter::VIEW, $comic)) {
            return $this->json(['message' => 'Access denied or comic not found'], Response::HTTP_FORBIDDEN);
        }

        return $this->json([
            'suggestions' => $suggestions->for($comic),
            // Tags the library already has that look like they belong to this
            // comic, and genres the file proposed. Nothing here creates a tag.
            // The owner's library, not the viewer's: these are the tags a save
            // would actually resolve against, so proposing anything else would
            // offer a choice the write path cannot honour.
            'tags' => $tagSuggestions->for($comic, $comic->getOwner() ?? $user),
            // Characters, teams, locations and story arcs. Shown as metadata and
            // never offered as tags — see ComicTagSuggestionService.
            'classification' => $comic->getClassification()->jsonSerialize() ?: null,
            // Named as the serializer names it. `origin` on its own meant two
            // different things in this API — which external record this comic
            // came from, and whose credential a lookup would spend — and only
            // one of those is the user's to see.
            'metadataOrigin' => $this->metadataOrigin($comic),
            // Which providers would answer this user, and why not when they
            // would not, so the editor can say something better than "no
            // results" before a search has even been run.
            'providers' => $providers->statusFor($user),
        ]);
    }

    /**
     * Records an external provider thinks might be this comic.
     *
     * A POST because the search is driven by the values currently in the edit
     * form, which the user may have accepted from a filename suggestion and not
     * yet saved. Making them save and reopen first was the flow break this
     * replaces.
     *
     * Those staged values are search hints and nothing more. What may be edited
     * is decided by the comic id and the voter, exactly as it is everywhere
     * else — a body cannot widen it.
     *
     * Editing the comic is what this leads to, so it takes the edit right
     * rather than the view right: a recipient a comic was shared with has no
     * business spending the installation's provider allowance on it.
     */
    #[Route('/{id}/metadata-candidates', name: 'metadata_candidates', methods: ['POST'])]
    public function metadataCandidates(
        int $id,
        Request $request,
        EntityManagerInterface $entityManager,
        MetadataProviderRegistry $providers,
        ComicMetadataSuggestionService $suggestions,
        RateLimiterFactory $metadataProviderUserLimiter
    ): JsonResponse {
        $user = $this->requireUser();

        $comic = $entityManager->getRepository(Comic::class)->find($id);
        if (!$comic) {
            return $this->json(['message' => 'Comic not found'], Response::HTTP_NOT_FOUND);
        }

        if (!$this->isGranted(ComicVoter::EDIT, $comic)) {
            return $this->json(['message' => 'Access denied or comic not found'], Response::HTTP_FORBIDDEN);
        }

        $data = \App\Http\JsonRequestDecoder::decode($request);

        $only = $data['provider'] ?? null;
        if ($only !== null && (!is_string($only) || $providers->get($only) === null)) {
            return $this->json(['message' => 'Unknown metadata provider.'], Response::HTTP_BAD_REQUEST);
        }

        // One person cannot spend the installation's whole hourly allowance
        // before anybody else opens an editor. Separate from the per-provider
        // ceiling, which protects the upstream account rather than the people
        // sharing it.
        if (!$metadataProviderUserLimiter->create((string) $user->getId())->consume()->isAccepted()) {
            return $this->json(
                ['message' => 'You have run a lot of metadata searches recently. Try again shortly.'],
                Response::HTTP_TOO_MANY_REQUESTS
            );
        }

        $query = ProviderQuery::staged($comic, is_array($data['query'] ?? null) ? $data['query'] : [], $suggestions->guess($comic));
        if ($query === null) {
            return $this->json([
                'message' => 'Give the comic a series or a title before searching.',
                'candidates' => [],
                'providers' => $providers->statusFor($user),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $lookup = $providers->search($query, $user, $only);

        // Each candidate carries what accepting it would actually change, so
        // the review UI never has to work that out for itself and what it shows
        // matches what applying would do.
        //
        // The provider reporting is the reduced one: which providers would
        // answer this user, never which account was spent or why a shared
        // credential was refused.
        return $this->json([
            'query' => ['series' => $query->series, 'issueNumber' => $query->issueNumber, 'year' => $query->year],
            'candidates' => array_map(
                fn ($candidate): array => [
                    'candidate' => $candidate,
                    'suggestions' => $suggestions->fromCandidate($comic, $candidate),
                ],
                $lookup->candidates
            ),
            'providers' => $providers->publicResults($lookup->providers),
        ]);
    }

    /**
     * One exact provider record, in full.
     *
     * A search row carries a fraction of what a provider knows — Metron's issue
     * list has no publisher, description or genres at all — so the rest is
     * fetched when somebody picks a candidate rather than for every row of
     * every search, which would be a request per result against a rate limit.
     */
    #[Route('/{id}/metadata-record', name: 'metadata_record', methods: ['POST'])]
    public function metadataRecord(
        int $id,
        Request $request,
        EntityManagerInterface $entityManager,
        MetadataProviderRegistry $providers,
        ComicMetadataSuggestionService $suggestions,
        ComicTagSuggestionService $tagSuggestions,
        RateLimiterFactory $metadataProviderUserLimiter
    ): JsonResponse {
        $data = \App\Http\JsonRequestDecoder::decode($request);

        return $this->respondWithRecord(
            $id,
            is_string($data['provider'] ?? null) ? $data['provider'] : null,
            is_string($data['externalId'] ?? null) || is_int($data['externalId'] ?? null) ? (string) $data['externalId'] : null,
            $entityManager,
            $providers,
            $suggestions,
            $tagSuggestions,
            $metadataProviderUserLimiter
        );
    }

    /**
     * Ask the provider again about the record this comic was matched to.
     *
     * Produces suggestions, exactly as a first search does. A refresh that
     * quietly overwrote the fields would undo every edit the user has made
     * since — the whole point of remembering the external id is to make the
     * question cheap, not to make the answer authoritative.
     */
    #[Route('/{id}/metadata-refresh', name: 'metadata_refresh', methods: ['POST'])]
    public function metadataRefresh(
        int $id,
        EntityManagerInterface $entityManager,
        MetadataProviderRegistry $providers,
        ComicMetadataSuggestionService $suggestions,
        ComicTagSuggestionService $tagSuggestions,
        RateLimiterFactory $metadataProviderUserLimiter
    ): JsonResponse {
        $comic = $entityManager->getRepository(Comic::class)->find($id);
        if ($comic && $comic->getMetadataProvider() === null) {
            return $this->json(
                ['message' => 'This comic has not been matched to a provider record yet. Search for one first.'],
                Response::HTTP_CONFLICT
            );
        }

        return $this->respondWithRecord(
            $id,
            $comic?->getMetadataProvider(),
            $comic?->getMetadataExternalId(),
            $entityManager,
            $providers,
            $suggestions,
            $tagSuggestions,
            $metadataProviderUserLimiter
        );
    }

    /**
     * The shared body of "fetch one record and say what it would change".
     *
     * Both callers need the same authorisation, the same failure vocabulary and
     * the same response shape; the only difference is where the record
     * reference came from.
     */
    private function respondWithRecord(
        int $id,
        ?string $providerKey,
        ?string $externalId,
        EntityManagerInterface $entityManager,
        MetadataProviderRegistry $providers,
        ComicMetadataSuggestionService $suggestions,
        ComicTagSuggestionService $tagSuggestions,
        RateLimiterFactory $metadataProviderUserLimiter
    ): JsonResponse {
        $user = $this->requireUser();

        $comic = $entityManager->getRepository(Comic::class)->find($id);
        if (!$comic) {
            return $this->json(['message' => 'Comic not found'], Response::HTTP_NOT_FOUND);
        }

        if (!$this->isGranted(ComicVoter::EDIT, $comic)) {
            return $this->json(['message' => 'Access denied or comic not found'], Response::HTTP_FORBIDDEN);
        }

        if ($providerKey === null || $externalId === null || $providers->get($providerKey) === null) {
            return $this->json(['message' => 'Name a provider and a record to look up.'], Response::HTTP_BAD_REQUEST);
        }

        // The same allowance the search consumes. A detail lookup is an upstream
        // request too, and a varying external id misses the cache, so leaving
        // this route unmetered would have let one account spend the whole
        // installation's quota through the fairness rule's back door.
        if (!$metadataProviderUserLimiter->create((string) $user->getId())->consume()->isAccepted()) {
            return $this->json(
                ['message' => 'You have run a lot of metadata lookups recently. Try again shortly.'],
                Response::HTTP_TOO_MANY_REQUESTS
            );
        }

        $result = $providers->detail($providerKey, $externalId, $user);
        $candidate = $result->candidates[0] ?? null;
        $status = $providers->publicResult($result);

        if ($candidate === null) {
            // The reduced reason, not the resolver's: a failed lookup must not
            // become a way to read back the shared credential's state.
            return $this->json([
                'message' => $status->reason ?? 'That record could not be read.',
                'provider' => $status,
            ], $result->isOk() ? Response::HTTP_NOT_FOUND : Response::HTTP_SERVICE_UNAVAILABLE);
        }

        return $this->json([
            'candidate' => $candidate,
            'suggestions' => $suggestions->fromCandidate($comic, $candidate),
            // Genres from the record, offered beside the library's own tags and
            // selected by nobody until somebody selects them.
            'tags' => $tagSuggestions->for($comic, $comic->getOwner() ?? $user, $candidate->classification, $candidate->provider),
            'provider' => $status,
        ]);
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

    /** @return array<string, mixed>|null */
    private function metadataOrigin(Comic $comic): ?array
    {
        if ($comic->getMetadataProvider() === null) {
            return null;
        }

        return [
            'provider' => $comic->getMetadataProvider(),
            'externalId' => $comic->getMetadataExternalId(),
            'fetchedAt' => $comic->getMetadataFetchedAt()?->format(\DateTimeInterface::ATOM),
        ];
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
        $data = \App\Http\JsonRequestDecoder::decode($request);
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

        $comic = $entityManager->getRepository(Comic::class)->find($id);

        if (!$comic) {
            return $this->json(['message' => 'Comic not found'], Response::HTTP_NOT_FOUND);
        }

        if (!$this->isGranted(ComicVoter::DELETE, $comic)) {
            return $this->json(['message' => 'You do not have permission to delete this comic'], Response::HTTP_FORBIDDEN);
        }

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
    
    /**
     * Everything the reader needs to lay a comic out before it downloads any of
     * it: how many pages, what sizes may be asked for, and the shape of the
     * pages that are already known.
     *
     * Geometry is a description of the comic, so it is behind exactly the same
     * check as the pages themselves — an explicit comic pending age
     * confirmation must not leak its page shapes any more than its artwork.
     * Nothing internal is exposed: no archive entry names, no filesystem paths.
     */
    #[Route('/{id}/pages', name: 'page_manifest', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function pageManifest(
        int $id,
        Request $request,
        EntityManagerInterface $entityManager,
        PageDerivativeService $derivatives
    ): Response {
        $this->requireUser();

        $comic = $entityManager->getRepository(Comic::class)->find($id);
        if (!$comic || !$this->isGranted(ComicVoter::VIEW, $comic)) {
            return $this->json(['message' => 'Comic not found'], Response::HTTP_NOT_FOUND);
        }

        $from = max(1, $request->query->getInt('from', 1));
        $manifest = $derivatives->describePages($comic, $from);

        $response = $this->json([
            'pageCount' => $comic->getPageCount() ?? 0,
            'variants' => PageVariant::widths(),
            'pages' => $manifest['pages'],
            'complete' => $manifest['complete'],
        ]);

        // A partial manifest grows as pages are read, so it is never worth a
        // browser holding on to: the next request is the point of asking again.
        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-store');
        $response->headers->set(AbstractSessionListener::NO_AUTO_CACHE_CONTROL_HEADER, 'true');

        return $response;
    }

    #[Route('/{id}/pages/{page}', name: 'get_page', methods: ['GET'])]
    public function getPage(
        int $id,
        int $page,
        Request $request,
        EntityManagerInterface $entityManager,
        ComicService $comicService,
        PageDerivativeService $derivatives
    ): Response {
        $user = $this->requireUser();

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

        // Refused rather than rounded to the nearest known size: an unknown
        // variant means the client and the server disagree about what exists,
        // and quietly serving something else hides that until somebody wonders
        // why a phone is downloading full-size scans.
        $variant = PageVariant::fromRequestValue($request->query->get('variant'));
        if ($variant === null) {
            return $this->json(
                ['message' => 'Unknown page variant.', 'variants' => array_keys(PageVariant::widths())],
                Response::HTTP_BAD_REQUEST
            );
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

        // Settle on one answer for "which file is this comic". The block above
        // decides whether the comic exists and migrates a legacy copy into the
        // owner's directory; ComicService is what the page cache keys itself
        // on. Re-resolving here, after any migration, keeps the validator below
        // describing the same file the cache does — otherwise a comic found by
        // two different candidate orders gets an ETag for one file and cached
        // pages for another.
        $filePath = $comicService->locateComicSource($comic) ?? $filePath;

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
            // The variant, the render version and the delivery format are all
            // part of the validator: a thumbnail and a full page live at the
            // same URL but for the query string, and a server that gains or
            // loses its WebP encoder starts producing different bytes for the
            // same page. A copy from before any of that must not be revalidated
            // as still current.
            $response->setEtag(hash(
                'sha256',
                $filePath . '|' . $modifiedAt . '|' . @filesize($filePath) . '|' . $page
                    . '|' . $derivatives->validatorSignature($variant)
            ));

            if ($response->isNotModified($request)) {
                return $response;
            }
        }

        try {
            $pageResult = $derivatives->getOrCreate($comic, $page, $variant)->page;
        } catch (\Throwable $exception) {
            $this->logger->error('Failed to read a comic source.', [
                'comic_id' => $comic->getId(),
                'exception' => $exception,
            ]);

            return $this->json(['message' => 'Failed to read comic page'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        // Return image, keeping the caching policy set up before the archive was
        // opened.
        $response->setContent($pageResult->content);
        $response->headers->set('Content-Type', $pageResult->mimeType);
        return $response;
    }

    /**
     * Download the original comic source.
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
        $user = $this->requireUser();

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

        $archivePath = $comicService->locateComicSource($comic);
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
        $response->headers->set('Content-Type', $comic->getSourceType()->mimeType());
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

        return mb_substr($safeTitle, 0, 100) . '.' . $comic->getSourceType()->value;
    }

    #[Route('/{id}/progress', name: 'update_progress', methods: ['POST'])]
    public function updateReadingProgressEndpoint(
        int $id, 
        Request $request, 
        EntityManagerInterface $entityManager
    ): JsonResponse {
        $user = $this->requireUser();

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
        $data = \App\Http\JsonRequestDecoder::decode($request);
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
        $currentUser = $this->requireUser();

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
