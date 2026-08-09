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
     * The codes an owner has handed out, newest first.
     *
     * Expired, spent and withdrawn ones are all included, right up until the
     * cleanup deletes them. The owner is asking what they gave away and how
     * many people took it up, and a code that has stopped working is part of
     * that answer — which is the whole reason dead codes are kept for a month
     * rather than dropped the moment they die.
     *
     * @return list<ShareClaimCode>
     */
    public function findForOwner(User $owner, int $limit = 30): array
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.owner = :owner')
            ->setParameter('owner', $owner)
            ->orderBy('c.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Codes whose retention window has passed, oldest first.
     *
     * Batched, so a cron job that has not run for a long time cannot hydrate
     * every dead code and its join rows into one unit of work.
     *
     * @return list<ShareClaimCode>
     */
    public function findDeletable(\DateTimeImmutable $now, int $limit): array
    {
        return $this->createQueryBuilder('c')
            // The window is measured from expiry for every dead code, withdrawn
            // or not: a code withdrawn on its first day still has an expiry, and
            // dating the sweep from one column keeps the rule easy to state.
            ->andWhere('c.expiresAt < :cutoff')
            ->setParameter('cutoff', $now->modify('-' . ltrim(ShareClaimCode::RETENTION_AFTER_EXPIRY, '+')))
            ->orderBy('c.expiresAt', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
