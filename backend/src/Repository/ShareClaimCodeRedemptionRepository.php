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
}
