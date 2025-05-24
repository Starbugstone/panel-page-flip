<?php

namespace App\Repository;

use App\Entity\ShareToken;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ShareToken>
 *
 * @method ShareToken|null find($id, $lockMode = null, $lockVersion = null)
 * @method ShareToken|null findOneBy(array $criteria, array $orderBy = null)
 * @method ShareToken[]    findAll()
 * @method ShareToken[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class ShareTokenRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ShareToken::class);
    }

    //    /**
    //     * @return ShareToken[] Returns an array of ShareToken objects
    //     */
    //    public function findByExampleField($value): array
    //    {
    //        return $this->createQueryBuilder('s')
    //            ->andWhere('s.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->orderBy('s.id', 'ASC')
    //            ->setMaxResults(10)
    //            ->getQuery()
    //            ->getResult()
    //        ;
    //    }

    //    public function findOneBySomeField($value): ?ShareToken
    //    {
    //        return $this->createQueryBuilder('s')
    //            ->andWhere('s.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->getQuery()
    //            ->getOneOrNullResult()
    //        ;
    //    }
}
