<?php

namespace App\Repository;

use App\Entity\ShareClaimCode;
use App\Entity\User;
use App\Service\SharingCodeFormat;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ShareClaimCode>
 */
class ShareClaimCodeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ShareClaimCode::class);
    }

    /**
     * Find a code by what somebody typed.
     *
     * By hash, so the plaintext is never compared against anything stored and a
     * database leak yields nothing redeemable. Returns whatever the hash finds,
     * live or not — deciding whether it may still be used belongs to the
     * service, which has to answer identically for a spent code and a code that
     * never existed.
     */
    public function findByPlaintext(string $plaintext): ?ShareClaimCode
    {
        $normalised = SharingCodeFormat::normalise($plaintext);
        if ($normalised === '') {
            return null;
        }

        return $this->findOneBy(['codeHash' => SharingCodeFormat::hash($normalised)]);
    }

    /**
     * The codes an owner still has out, newest first.
     *
     * Expired and spent ones are included: the owner asked what they handed
     * out, and a code that has run out is part of that answer.
     *
     * @return list<ShareClaimCode>
     */
    public function findLiveForOwner(User $owner, int $limit = 20): array
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.owner = :owner')
            ->andWhere('c.revokedAt IS NULL')
            ->setParameter('owner', $owner)
            ->orderBy('c.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
