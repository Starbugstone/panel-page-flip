<?php

namespace App\Repository;

use App\Entity\Tag;
use App\Entity\User;
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
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Tag::class);
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
