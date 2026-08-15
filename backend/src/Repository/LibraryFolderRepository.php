<?php

namespace App\Repository;

use App\Entity\LibraryFolder;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<LibraryFolder> */
class LibraryFolderRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, LibraryFolder::class);
    }

    /** @return list<LibraryFolder> */
    public function findForOwner(User $owner): array
    {
        return $this->createQueryBuilder('f')
            ->andWhere('f.owner = :owner')
            ->setParameter('owner', $owner)
            ->orderBy('LOWER(f.name)', 'ASC')
            ->addOrderBy('f.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findOwned(int $id, User $owner): ?LibraryFolder
    {
        return $this->findOneBy(['id' => $id, 'owner' => $owner]);
    }

    public function siblingNameExists(
        User $owner,
        ?LibraryFolder $parent,
        string $name,
        ?LibraryFolder $except = null
    ): bool {
        $qb = $this->createQueryBuilder('f')
            ->select('COUNT(f.id)')
            ->andWhere('f.owner = :owner')
            ->andWhere('LOWER(f.name) = :name')
            ->setParameter('owner', $owner)
            ->setParameter('name', mb_strtolower($name));

        if ($parent === null) {
            $qb->andWhere('f.parent IS NULL');
        } else {
            $qb->andWhere('f.parent = :parent')->setParameter('parent', $parent);
        }

        if ($except !== null) {
            $qb->andWhere('f != :except')->setParameter('except', $except);
        }

        return (int) $qb->getQuery()->getSingleScalarResult() > 0;
    }
}
