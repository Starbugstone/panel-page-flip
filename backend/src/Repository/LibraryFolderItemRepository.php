<?php

namespace App\Repository;

use App\Entity\Comic;
use App\Entity\LibraryFolder;
use App\Entity\LibraryFolderItem;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<LibraryFolderItem> */
class LibraryFolderItemRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, LibraryFolderItem::class);
    }

    /**
     * @param list<Comic> $comics
     * @return array<int, int> comic id => folder id
     */
    public function findFolderIdsByUserAndComics(User $user, array $comics): array
    {
        if ($comics === []) {
            return [];
        }

        $rows = $this->createQueryBuilder('i')
            ->select('IDENTITY(i.comic) AS comicId', 'IDENTITY(i.folder) AS folderId')
            ->andWhere('i.user = :user')
            ->andWhere('i.comic IN (:comics)')
            ->setParameter('user', $user)
            ->setParameter('comics', $comics)
            ->getQuery()
            ->getArrayResult();

        $indexed = [];
        foreach ($rows as $row) {
            $indexed[(int) $row['comicId']] = (int) $row['folderId'];
        }

        return $indexed;
    }

    /**
     * @param list<LibraryFolder> $folders
     *
     * @return list<LibraryFolderItem>
     */
    public function findInFolders(User $user, array $folders): array
    {
        if ($folders === []) {
            return [];
        }

        return $this->createQueryBuilder('i')
            ->addSelect('c')
            ->join('i.comic', 'c')
            ->andWhere('i.user = :user')
            ->andWhere('i.folder IN (:folders)')
            ->setParameter('user', $user)
            ->setParameter('folders', $folders)
            ->getQuery()
            ->getResult();
    }
}
