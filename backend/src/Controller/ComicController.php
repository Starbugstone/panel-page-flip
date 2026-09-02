<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Comic;
use App\Entity\ComicShare;
use App\Entity\Tag;
use App\Metadata\StructuredMetadataInput;
use App\Repository\ComicRepository;
use App\Repository\TagRepository;
use App\Security\ComicAccess;
use App\Security\Voter\ComicVoter;
use App\Service\AdminAuditService;
use App\Service\ApiRateLimiter;
use App\Service\ComicLibraryQueryService;
use App\Service\ComicSerializer;
use App\Service\ComicService;
use App\Service\ComicShareService;
use App\Service\LibraryFolderService;
use App\Service\MetadataProviderRegistry;
use App\Service\Pagination\PaginationRequest;
use App\Service\SecurityAuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Query and mutate one comic as a library entry.
 *
 * The routes that receive an upload, talk to a metadata provider, or serve the
 * actual bytes, update progress, or change several entries are their own
 * controllers. They share this prefix but not their collaborators.
 */
#[Route('/api/comics', name: 'api_comics_')]
final class ComicController extends AbstractController
{
    use RequiresAuthenticatedUser;

    public function __construct(
        private readonly ComicSerializer $comicSerializer,
        private readonly ComicAccess $comicAccess,
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
            $page = $comicRepository->findAdminPage($pagination, $ownerId, [
                'titleAuthor' => $request->query->get('filterTitleAuthor'),
                'owner' => $request->query->get('filterOwner'),
                'uploadedAt' => $request->query->get('filterUploadedAt'),
                'pageCount' => $request->query->get('filterPageCount'),
                'tags' => $request->query->get('filterTags'),
                'timezone' => $request->query->get('filterTimezone'),
            ]);
            $comics = $this->comicSerializer->serializeMany($page->items, $user, true);

            return $this->json([
                'items' => $comics,
                'comics' => $comics,
                'pageCountMax' => $comicRepository->getMaximumPageCount($ownerId),
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

    #[Route('/{id}', name: 'get', methods: ['GET'])]
    public function get(int $id): JsonResponse
    {
        $user = $this->requireUser();

        // Owner, administrator or accepted recipient — the voter is the only
        // place that answers this.
        $comic = $this->comicAccess->requireComic($id, ComicVoter::VIEW);

        return $this->json(['comic' => $this->comicSerializer->serialize($comic, $user)]);
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
        // false — is a change the same way ticking it is.
        if (array_key_exists('explicitContent', $data) && is_bool($data['explicitContent'])) {
            $comic->setExplicitContent($data['explicitContent']);
        }

        // Accepting a suggestion is an ordinary edit, so it arrives here rather
        // than through a route of its own and is authorised the same way.
        $structured = new StructuredMetadataInput($providers->keys());
        if (!$structured->applyTo($data, $comic)) {
            return $this->json(
                ['message' => implode(' ', $structured->errors())],
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
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


}
