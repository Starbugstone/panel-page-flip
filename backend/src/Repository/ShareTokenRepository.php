<?php

namespace App\Repository;

use App\Entity\ShareToken;
use App\Entity\User;
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
    
    /**
     * Count recent share tokens created by a user within a specific time period
     * Used for rate limiting share invitations
     *
     * @param User $user The user who created the share tokens
     * @param \DateTimeInterface $since The datetime since when to count shares
     * @return int Number of shares created by the user since the specified time
     */
    public function countRecentSharesByUser(User $user, \DateTimeInterface $since): int
    {
        return $this->createQueryBuilder('s')
            ->select('COUNT(s.id)')
            ->andWhere('s.sharedByUser = :user')
            ->andWhere('s.createdAt >= :since')
            ->setParameter('user', $user)
            ->setParameter('since', $since)
            ->getQuery()
            ->getSingleScalarResult();
    }
    
    /**
     * Find pending share tokens for a specific email address
     * 
     * @param string $email The email address to find pending shares for
     * @return ShareToken[] Array of pending share tokens
     */
    public function findPendingSharesByEmail(string $email): array
    {
        $now = new \DateTimeImmutable();
        
        return $this->createQueryBuilder('s')
            ->andWhere('s.sharedWithEmail = :email')
            ->andWhere('s.isUsed = :isUsed')
            ->andWhere('s.expiresAt > :now')
            ->setParameter('email', $email)
            ->setParameter('isUsed', false)
            ->setParameter('now', $now)
            ->orderBy('s.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
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
