<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Comic;
use App\Entity\ComicShare;
use App\Entity\Tag;
use App\Entity\User;
use App\Metadata\StructuredMetadataInput;
use App\Repository\TagRepository;
use App\Service\ComicService;
use App\Service\ComicShareService;
use App\Service\MetadataProviderRegistry;
use App\Service\SecurityAuditLogger;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Multi-comic mutations, including their all-or-nothing validation. */
#[Route('/api/comics', name: 'api_comics_')]
final class ComicBulkController extends AbstractController
{
    use RequiresAuthenticatedUser;

    private const BULK_TITLE_UPDATE_LIMIT = 5000;
    private const BULK_DELETE_ALERT_THRESHOLD = 25;

    public function __construct(private readonly LoggerInterface $logger)
    {
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
            $structured = new StructuredMetadataInput($providers->keys());
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

    #[Route('/titles', name: 'batch_update_titles', methods: ['PATCH'])]
    public function batchUpdateTitles(Request $request, EntityManagerInterface $entityManager): JsonResponse
    {
        $user = $this->requireUser();
        $data = \App\Http\JsonRequestDecoder::decode($request);
        $updates = $this->normaliseComicTitleUpdates($data['updates'] ?? null);
        if ($updates === []) {
            return $this->json(['message' => 'A valid title updates array is required'], Response::HTTP_BAD_REQUEST);
        }

        $comicIds = array_column($updates, 'id');
        $entityManager->beginTransaction();
        try {
            $comics = $entityManager->createQueryBuilder()
                ->select('comic')
                ->from(Comic::class, 'comic')
                ->where('comic.id IN (:ids)')
                ->andWhere('comic.owner = :owner')
                ->setParameter('ids', $comicIds)
                ->setParameter('owner', $user)
                ->getQuery()
                ->setLockMode(LockMode::PESSIMISTIC_WRITE)
                ->getResult();
            if (count($comics) !== count($comicIds)) {
                $entityManager->rollback();

                return $this->json(['message' => 'One or more comics were not found'], Response::HTTP_NOT_FOUND);
            }

            $comicsById = [];
            foreach ($comics as $comic) {
                $comicsById[$comic->getId()] = $comic;
            }
            foreach ($updates as $update) {
                if ($comicsById[$update['id']]->getTitle() !== $update['currentTitle']) {
                    $entityManager->rollback();

                    return $this->json(
                        ['message' => 'One or more comic titles changed. Preview the rename again.'],
                        Response::HTTP_CONFLICT
                    );
                }
            }

            foreach ($updates as $update) {
                $comicsById[$update['id']]->setTitle($update['title']);
            }
            $entityManager->flush();
            $entityManager->commit();
        } catch (\Throwable $exception) {
            if ($entityManager->getConnection()->isTransactionActive()) {
                $entityManager->rollback();
            }
            throw $exception;
        }

        return $this->json([
            'message' => sprintf('%d comic title(s) updated', count($updates)),
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
        $comicIds = $this->normaliseBulkComicIds($data['comicIds'] ?? null);
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

    /**
     * @return list<array{id: int, currentTitle: string, title: string}>
     */
    private function normaliseComicTitleUpdates(mixed $updates): array
    {
        if (!is_array($updates) || $updates === [] || count($updates) > self::BULK_TITLE_UPDATE_LIMIT) {
            return [];
        }

        $normalised = [];
        foreach ($updates as $update) {
            if (!is_array($update) || array_diff(array_keys($update), ['id', 'currentTitle', 'title']) !== []) {
                return [];
            }

            $ids = $this->normaliseBulkComicIds([$update['id'] ?? null]);
            $currentTitle = $update['currentTitle'] ?? null;
            $title = $update['title'] ?? null;
            if (
                count($ids) !== 1
                || !is_string($currentTitle)
                || !is_string($title)
                || mb_strlen($currentTitle) > 255
                || trim($title) === ''
                || mb_strlen(trim($title)) > 255
                || isset($normalised[$ids[0]])
            ) {
                return [];
            }

            $normalised[$ids[0]] = [
                'id' => $ids[0],
                'currentTitle' => $currentTitle,
                'title' => trim($title),
            ];
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
