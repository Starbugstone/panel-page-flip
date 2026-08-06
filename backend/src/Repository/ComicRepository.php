<?php

namespace App\Repository;

use App\Entity\Comic;
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
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Comic::class);
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
