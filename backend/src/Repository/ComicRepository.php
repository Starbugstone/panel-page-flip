<?php

namespace App\Repository;

use App\Entity\Comic;
use App\Entity\Tag;
use App\Service\Pagination\PaginatedResult;
use App\Service\Pagination\PaginationRequest;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Comic>
 *
 * @method Comic|null find($id, $lockMode = null, $lockVersion = null)
 * @method Comic|null findOneBy(array $criteria, array $orderBy = null)
 * @method Comic[]    findAll()
 * @method Comic[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class ComicRepository extends ServiceEntityRepository
{
    /**
     * The one definition of "storage used": the canonical source bytes an owner
     * is accountable for.
     *
     * Upload admission, the admin user list and the installation total all read
     * their figure from here. They ask different questions of it — one owner, a
     * page of owners, everybody — but they cannot drift into different answers
     * about the same comics. Generated pages, thumbnails and upload chunks are
     * absent on purpose: they are rebuildable cache and count against no quota.
     */
    private const STORAGE_BYTES = 'COALESCE(SUM(c.fileSize), 0)';

    /**
     * `fileSize` arrived after comics could already exist, and the backfill
     * leaves it null when the source file cannot be located. SUM() then skips
     * those rows without complaint, so they are counted here instead of
     * quietly understating a total that is presented as exact.
     */
    private const UNMEASURED_COMICS = 'SUM(CASE WHEN c.fileSize IS NULL THEN 1 ELSE 0 END)';

    /** Sortable columns for the admin comic table, as query alias => DQL field. */
    public const ADMIN_SORT_FIELDS = [
        'title' => 'c.title',
        'author' => 'c.author',
        'uploadedAt' => 'c.uploadedAt',
        'pageCount' => 'c.pageCount',
        'fileSize' => 'c.fileSize',
    ];

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Comic::class);
    }

    /**
     * One page of the admin comic list, across every owner unless one is named.
     *
     * @param int|null $ownerId Restrict to a single owner's library.
     * @return PaginatedResult<Comic>
     */
    public function findAdminPage(PaginationRequest $request, ?int $ownerId = null): PaginatedResult
    {
        $qb = $this->createQueryBuilder('c')->leftJoin('c.owner', 'o');

        if ($ownerId !== null) {
            $qb->andWhere('c.owner = :ownerId')->setParameter('ownerId', $ownerId);
        }

        if ($pattern = $request->searchPattern()) {
            // The tag match runs as an EXISTS subquery rather than a join, so a
            // comic carrying two matching tags is still one row and the count
            // below stays honest.
            $taggedSubquery = $this->getEntityManager()->createQueryBuilder()
                ->select('1')
                ->from(Tag::class, 'searchTag')
                ->join('searchTag.comics', 'taggedComic')
                ->where('taggedComic = c')
                ->andWhere('LOWER(searchTag.name) LIKE :search')
                ->getDQL();

            $qb->andWhere($qb->expr()->orX(
                'LOWER(c.title) LIKE :search',
                'LOWER(c.author) LIKE :search',
                'LOWER(c.publisher) LIKE :search',
                'LOWER(c.description) LIKE :search',
                'LOWER(o.name) LIKE :search',
                'LOWER(o.email) LIKE :search',
                $qb->expr()->exists($taggedSubquery),
            ))->setParameter('search', $pattern);
        }

        $total = (int) (clone $qb)->select('COUNT(c.id)')
            ->getQuery()
            ->getSingleScalarResult();

        $comics = $qb
            ->orderBy(self::ADMIN_SORT_FIELDS[$request->sortField], $request->direction)
            ->addOrderBy('c.id', 'DESC')
            ->setFirstResult($request->offset())
            ->setMaxResults($request->limit)
            ->getQuery()
            ->getResult();

        return PaginatedResult::fromRequest($comics, $total, $request);
    }

    /**
     * How many comics each of these owners has with a given description,
     * indexed by owner id.
     *
     * One grouped query for the whole page, because the caller needs the figure
     * per owner and asking per owner means loading each owner's entire library
     * to count a subset of it.
     *
     * @param list<int> $ownerIds
     * @return array<int, int>
     */
    public function countByOwnerWithDescription(array $ownerIds, string $description): array
    {
        if ($ownerIds === []) {
            return [];
        }

        $rows = $this->createQueryBuilder('c')
            ->select('IDENTITY(c.owner) AS ownerId', 'COUNT(c.id) AS total')
            ->andWhere('c.owner IN (:ownerIds)')
            ->andWhere('c.description = :description')
            ->setParameter('ownerIds', $ownerIds)
            ->setParameter('description', $description)
            ->groupBy('c.owner')
            ->getQuery()
            ->getArrayResult();

        $counts = [];
        foreach ($rows as $row) {
            $counts[(int) $row['ownerId']] = (int) $row['total'];
        }

        return $counts;
    }

    /**
     * Hydrate the tag and owner associations of an already-loaded comic list in
     * one query. Serializing a library otherwise lazy-loads each comic's tag
     * collection and owner separately, which costs a query per comic and is the
     * bulk of the delay before the library can render.
     *
     * The comics are loaded again rather than fetch-joined into the caller's
     * query on purpose: the list endpoint groups and filters on the same tag
     * join, and adding a fetch join there would collide with its GROUP BY.
     *
     * @param list<Comic> $comics
     */
    public function preloadAssociations(array $comics): void
    {
        if ($comics === []) {
            return;
        }

        $this->createQueryBuilder('c')
            ->addSelect('t', 'o')
            ->leftJoin('c.tags', 't')
            ->leftJoin('c.owner', 'o')
            ->andWhere('c IN (:comics)')
            ->setParameter('comics', $comics)
            ->getQuery()
            ->getResult();
    }

    /**
     * Comic count, storage bytes and unmeasured-comic count per owner, keyed by
     * owner id, for the owners asked about.
     *
     * One grouped query rather than one per owner: the admin user list renders a
     * whole page from this. The count and the byte total come out of the same
     * query so a comic cannot be counted by one and missed by the other.
     *
     * @param list<int> $ownerIds
     * @return array<int, array{comicCount: int, storageUsedBytes: int, unmeasuredComicCount: int}>
     */
    public function getStorageStatsByOwner(array $ownerIds): array
    {
        if ($ownerIds === []) {
            return [];
        }

        $stats = array_fill_keys(
            $ownerIds,
            ['comicCount' => 0, 'storageUsedBytes' => 0, 'unmeasuredComicCount' => 0]
        );

        $rows = $this->createQueryBuilder('c')
            ->select(
                'IDENTITY(c.owner) AS ownerId',
                'COUNT(c.id) AS comicCount',
                self::STORAGE_BYTES . ' AS storageUsedBytes',
                self::UNMEASURED_COMICS . ' AS unmeasuredComicCount',
            )
            ->where('c.owner IN (:ownerIds)')
            ->groupBy('c.owner')
            ->setParameter('ownerIds', $ownerIds)
            ->getQuery()
            ->getScalarResult();

        foreach ($rows as $row) {
            // BIGINT aggregates arrive as strings on every driver; cast once here
            // so callers and the API payload deal in integers throughout.
            $stats[(int) $row['ownerId']] = [
                'comicCount' => (int) $row['comicCount'],
                'storageUsedBytes' => (int) $row['storageUsedBytes'],
                'unmeasuredComicCount' => (int) $row['unmeasuredComicCount'],
            ];
        }

        return $stats;
    }

    /** What one owner has used, as quota admission counts it. */
    public function getStorageBytesForOwner(int $ownerId): int
    {
        return $this->getStorageStatsByOwner([$ownerId])[$ownerId]['storageUsedBytes'];
    }

    /** Every comic on the installation, for the admin dashboard total. */
    public function getTotalStorageBytes(): int
    {
        return (int) $this->createQueryBuilder('c')
            ->select(self::STORAGE_BYTES)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
