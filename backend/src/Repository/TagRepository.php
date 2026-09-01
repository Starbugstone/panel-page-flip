<?php

namespace App\Repository;

use App\Entity\Tag;
use App\Entity\User;
use App\Service\Pagination\ColumnFilter;
use App\Service\Pagination\PaginatedResult;
use App\Service\Pagination\PaginationRequest;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Tag>
 *
 * @method Tag|null find($id, $lockMode = null, $lockVersion = null)
 * @method Tag|null findOneBy(array $criteria, array $orderBy = null)
 * @method Tag[]    findAll()
 * @method Tag[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class TagRepository extends ServiceEntityRepository
{
    /** Sortable columns for the admin tag table, as query alias => DQL field. */
    public const ADMIN_SORT_FIELDS = [
        'name' => 't.name',
        'createdAt' => 't.createdAt',
        'isGlobal' => 't.isGlobal',
        'hideFromLibrary' => 't.hideFromLibrary',
        'comicCount' => 'SIZE(t.comics)',
        'creator' => 'creator.email',
    ];

    /** The Scope column's two values, as its cells spell them. */
    private const SCOPE_LABELS = ['global' => 'Global', 'personal' => 'Personal'];

    /** Likewise for the Default library column. */
    private const VISIBILITY_LABELS = ['visible' => 'Visible', 'hidden' => 'Hidden'];

    /** Upper bound on an autocomplete response; nobody scrolls past this. */
    public const SEARCH_LIMIT = 50;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Tag::class);
    }

    /**
     * One page of the admin tag list, across the whole install unless a creator
     * is named.
     *
     * @param int|null $creatorId Restrict to one user's personal tags. Global
     *                            tags have no creator and are excluded by it.
     * @param array{name?: string|null, scope?: string|null, visibility?: string|null,
     *               comicCount?: string|null, creator?: string|null, createdAt?: string|null} $filters
     * @return PaginatedResult<Tag>
     */
    public function findAdminPage(PaginationRequest $request, ?int $creatorId = null, array $filters = []): PaginatedResult
    {
        $qb = $this->createQueryBuilder('t')->leftJoin('t.creator', 'creator');

        if ($creatorId !== null) {
            $qb->andWhere('t.creator = :creatorId')->setParameter('creatorId', $creatorId);
        }

        if ($pattern = $request->searchPattern()) {
            $qb->andWhere($qb->expr()->orX(
                'LOWER(t.name) LIKE :search',
                'LOWER(creator.name) LIKE :search',
                'LOWER(creator.email) LIKE :search',
            ))->setParameter('search', $pattern);
        }

        if ($pattern = ColumnFilter::pattern($filters['name'] ?? null)) {
            $qb->andWhere('LOWER(t.name) LIKE :filterName')->setParameter('filterName', $pattern);
        }

        $scope = ColumnFilter::matchLabel($qb, $filters['scope'] ?? null, self::SCOPE_LABELS);
        if ($scope !== null) {
            $qb->andWhere('t.isGlobal = :filterScope')->setParameter('filterScope', $scope === 'global');
        }

        $visibility = ColumnFilter::matchLabel($qb, $filters['visibility'] ?? null, self::VISIBILITY_LABELS);
        if ($visibility !== null) {
            $qb->andWhere('t.hideFromLibrary = :filterVisibility')
                ->setParameter('filterVisibility', $visibility === 'hidden');
        }

        $comicCount = ColumnFilter::text($filters['comicCount'] ?? null);
        if (ctype_digit($comicCount)) {
            $qb->andWhere('SIZE(t.comics) = :filterComicCount')->setParameter('filterComicCount', (int) $comicCount);
        }

        if ($pattern = ColumnFilter::pattern($filters['creator'] ?? null)) {
            if (mb_strtolower(ColumnFilter::text($filters['creator'])) === 'system') {
                $qb->andWhere('t.creator IS NULL');
            } else {
                $qb->andWhere($qb->expr()->orX(
                    'LOWER(creator.name) LIKE :filterCreator',
                    'LOWER(creator.email) LIKE :filterCreator',
                ))->setParameter('filterCreator', $pattern);
            }
        }

        ColumnFilter::applyDay($qb, 't.createdAt', 'filterCreatedAt', $filters['createdAt'] ?? null);

        $total = (int) (clone $qb)->select('COUNT(t.id)')
            ->getQuery()
            ->getSingleScalarResult();

        $tags = $qb
            ->orderBy(self::ADMIN_SORT_FIELDS[$request->sortField], $request->direction)
            ->addOrderBy('t.id', 'DESC')
            ->setFirstResult($request->offset())
            ->setMaxResults($request->limit)
            ->getQuery()
            ->getResult();

        return PaginatedResult::fromRequest($tags, $total, $request);
    }

    /**
     * How many comics carry each of the given tags, keyed by tag id.
     *
     * One grouped query instead of counting each tag's comic collection, which
     * costs a query per row.
     *
     * @param list<Tag> $tags
     * @return array<int, int>
     */
    public function countComicsPerTag(array $tags): array
    {
        if ($tags === []) {
            return [];
        }

        $counts = array_fill_keys(array_map(static fn (Tag $tag): int => $tag->getId(), $tags), 0);

        $rows = $this->getEntityManager()->createQueryBuilder()
            ->select('t.id AS tagId', 'COUNT(c.id) AS total')
            ->from(Tag::class, 't')
            ->join('t.comics', 'c')
            ->where('t IN (:tags)')
            ->groupBy('t.id')
            ->setParameter('tags', $tags)
            ->getQuery()
            ->getScalarResult();
        foreach ($rows as $row) {
            $counts[(int) $row['tagId']] = (int) $row['total'];
        }

        return $counts;
    }

    /**
     * Tags matching a partial name, for the tag autocomplete.
     *
     * Both the scope and the limit are applied in the query. They used to be
     * neither: every tag in the install whose name matched was hydrated and
     * then filtered down to the caller's own in PHP, so one keystroke against a
     * large install loaded the whole tag table to return a handful of rows.
     *
     * @param User|null $visibleTo Restrict to what this user may see — global
     *                             tags plus their own. Null keeps every tag,
     *                             for the admin tag table.
     * @return list<Tag>
     */
    public function findByNameLike(string $name, ?User $visibleTo = null, int $limit = self::SEARCH_LIMIT): array
    {
        $qb = $this->createQueryBuilder('t')
            ->where('LOWER(t.name) LIKE LOWER(:name)')
            ->setParameter('name', '%' . strtolower($name) . '%')
            ->orderBy('t.name', 'ASC')
            ->setMaxResults($limit);

        if ($visibleTo !== null) {
            $qb->andWhere($qb->expr()->orX('t.isGlobal = true', 't.creator = :visibleTo'))
                ->setParameter('visibleTo', $visibleTo);
        }

        return $qb->getQuery()->getResult();
    }

    /** @return list<Tag> */
    public function findAvailableForUser(User $user): array
    {
        return $this->createQueryBuilder('t')
            ->where('t.isGlobal = true')
            ->orWhere('t.creator = :user')
            ->setParameter('user', $user)
            ->orderBy('t.isGlobal', 'DESC')
            ->addOrderBy('t.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findAvailableByName(string $name, User $user): ?Tag
    {
        return $this->createQueryBuilder('t')
            ->where('LOWER(t.name) = LOWER(:name)')
            ->andWhere('(t.isGlobal = true OR t.creator = :user)')
            ->setParameter('name', $name)
            ->setParameter('user', $user)
            ->orderBy('t.isGlobal', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findGlobalByName(string $name): ?Tag
    {
        return $this->createQueryBuilder('t')
            ->where('t.isGlobal = true')
            ->andWhere('LOWER(t.name) = LOWER(:name)')
            ->setParameter('name', $name)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findPersonalByName(string $name): ?Tag
    {
        return $this->createQueryBuilder('t')
            ->where('t.isGlobal = false')
            ->andWhere('LOWER(t.name) = LOWER(:name)')
            ->setParameter('name', $name)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /** @param list<string> $names */
    public function hasLibraryHidingGlobalTag(array $names): bool
    {
        if ($names === []) {
            return false;
        }

        return (int) $this->createQueryBuilder('t')
            ->select('COUNT(t.id)')
            ->where('t.isGlobal = true')
            ->andWhere('t.hideFromLibrary = true')
            ->andWhere('LOWER(t.name) IN (:names)')
            ->setParameter('names', array_map('mb_strtolower', $names))
            ->getQuery()
            ->getSingleScalarResult() > 0;
    }
}
