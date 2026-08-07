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
}
