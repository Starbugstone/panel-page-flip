<?php

namespace App\Repository;

use App\Entity\ShareClaimCode;
use App\Entity\ShareClaimCodeRedemption;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ShareClaimCodeRedemption>
 */
class ShareClaimCodeRedemptionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ShareClaimCodeRedemption::class);
    }

    public function findFor(ShareClaimCode $claimCode, User $recipient): ?ShareClaimCodeRedemption
    {
        return $this->findOneBy(['claimCode' => $claimCode, 'recipient' => $recipient]);
    }

    /**
     * How many distinct accounts have taken this offer up.
     *
     * The honest answer to the question the owner is asking, as opposed to the
     * counter, which only knows how many uses have been spent.
     */
    public function countFor(ShareClaimCode $claimCode): int
    {
        return (int) $this->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->andWhere('r.claimCode = :code')
            ->setParameter('code', $claimCode)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
