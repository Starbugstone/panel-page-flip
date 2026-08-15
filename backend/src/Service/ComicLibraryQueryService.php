<?php

namespace App\Service;

use App\Entity\Comic;
use App\Entity\LibraryFolder;
use App\Entity\LibraryFolderItem;
use App\Entity\Tag;
use App\Entity\User;
use App\Repository\ComicShareRepository;
use App\Repository\TagRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;

/**
 * The server-authoritative definition of one viewer's library.
 *
 * Every folder consumer goes through the same owner/share/content-hiding
 * boundary as the ordinary dashboard list, so folder ids can never become an
 * alternate way to discover a comic the viewer cannot currently access.
 */
class ComicLibraryQueryService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ComicShareRepository $shareRepository,
        private readonly TagRepository $tagRepository
    ) {
    }

    /**
     * @param list<string> $tagNames
     * @return list<Comic>
     */
    public function findVisibleLibrary(
        User $viewer,
        string $ownership = 'all',
        ?string $search = null,
        array $tagNames = [],
        LibraryFolder|string|null $folder = null
    ): array {
        $qb = $this->visibleQueryBuilder($viewer, $ownership, $tagNames);

        if ($search !== null && trim($search) !== '') {
            $qb->andWhere($qb->expr()->orX(
                $qb->expr()->like('LOWER(c.title)', ':search'),
                $qb->expr()->like('LOWER(c.description)', ':search'),
                $qb->expr()->like('LOWER(c.author)', ':search'),
                $qb->expr()->like('LOWER(c.publisher)', ':search')
            ))->setParameter('search', '%' . mb_strtolower(trim($search)) . '%');
        }

        if ($tagNames !== []) {
            $qb->join('c.tags', 't')
                ->andWhere('LOWER(t.name) IN (:tagNames)')
                ->setParameter('tagNames', array_map('mb_strtolower', $tagNames))
                ->groupBy('c.id')
                ->having('COUNT(DISTINCT t.id) = :tagCount')
                ->setParameter('tagCount', count($tagNames));
        }

        if ($folder !== null) {
            $qb->leftJoin(
                LibraryFolderItem::class,
                'libraryItem',
                'WITH',
                'libraryItem.comic = c AND libraryItem.user = :folderViewer'
            )->setParameter('folderViewer', $viewer);

            if ($folder === 'root') {
                $qb->andWhere('libraryItem.id IS NULL');
            } else {
                $qb->andWhere('libraryItem.folder = :folder')->setParameter('folder', $folder);
            }
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Resolve a batch without disclosing which requested id was inaccessible.
     *
     * @param list<int> $comicIds
     * @return list<Comic>
     */
    public function findVisibleByIds(User $viewer, array $comicIds): array
    {
        if ($comicIds === []) {
            return [];
        }

        return $this->visibleQueryBuilder($viewer, 'all', [])
            ->andWhere('c.id IN (:requestedComicIds)')
            ->setParameter('requestedComicIds', $comicIds)
            ->getQuery()
            ->getResult();
    }

    /**
     * Access validation for organisation actions. A hide-from-library tag is a
     * shelving preference rather than an access revocation, so a comic reached
     * through an explicit hiding-tag search can still be moved.
     *
     * @param list<int> $comicIds
     * @return list<Comic>
     */
    public function findAccessibleByIds(User $viewer, array $comicIds): array
    {
        if ($comicIds === []) {
            return [];
        }

        return $this->visibleQueryBuilder($viewer, 'all', [], false)
            ->andWhere('c.id IN (:requestedComicIds)')
            ->setParameter('requestedComicIds', $comicIds)
            ->getQuery()
            ->getResult();
    }

    /** @param list<string> $requestedTagNames */
    private function visibleQueryBuilder(
        User $viewer,
        string $ownership,
        array $requestedTagNames,
        bool $applyLibraryHiding = true
    ): QueryBuilder
    {
        if (!in_array($ownership, ['all', 'mine', 'shared'], true)) {
            $ownership = 'all';
        }

        $qb = $this->entityManager->createQueryBuilder()
            ->select('c')
            ->from(Comic::class, 'c');

        $sharedComicIds = $ownership === 'mine'
            ? []
            : $this->shareRepository->findVisibleCollectionComicIds($viewer);

        if ($ownership === 'shared') {
            if ($sharedComicIds === []) {
                $qb->andWhere('1 = 0');
            } else {
                $qb->andWhere('c.id IN (:sharedComicIds)')
                    ->setParameter('sharedComicIds', $sharedComicIds);
            }
        } elseif ($sharedComicIds === []) {
            $qb->andWhere('c.owner = :libraryOwner')->setParameter('libraryOwner', $viewer);
        } else {
            $qb->andWhere($qb->expr()->orX(
                'c.owner = :libraryOwner',
                'c.id IN (:sharedComicIds)'
            ))
                ->setParameter('libraryOwner', $viewer)
                ->setParameter('sharedComicIds', $sharedComicIds);
        }

        if ($applyLibraryHiding && !$this->tagRepository->hasLibraryHidingGlobalTag($requestedTagNames)) {
            $hiddenTagSubquery = $this->entityManager->createQueryBuilder()
                ->select('1')
                ->from(Tag::class, 'libraryHidingTag')
                ->join('libraryHidingTag.comics', 'hiddenComic')
                ->where('hiddenComic = c')
                ->andWhere('libraryHidingTag.hideFromLibrary = true')
                ->getDQL();
            $qb->andWhere($qb->expr()->not($qb->expr()->exists($hiddenTagSubquery)));
        }

        return $qb;
    }
}
