<?php

namespace App\Repository;

use App\Entity\Tag;
use App\Entity\User;
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
    ];

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
     * @return PaginatedResult<Tag>
     */
    public function findAdminPage(PaginationRequest $request, ?int $creatorId = null): PaginatedResult
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

        $total = (int) (clone $qb)->select('COUNT(t.id)')
            ->resetDQLPart('orderBy')
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
     * Find tags by partial name match
     */
    public function findByNameLike(string $name)
    {
        return $this->createQueryBuilder('t')
            ->where('LOWER(t.name) LIKE LOWER(:name)')
            ->setParameter('name', '%' . strtolower($name) . '%')
            ->orderBy('t.name', 'ASC')
            ->getQuery()
            ->getResult();
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
