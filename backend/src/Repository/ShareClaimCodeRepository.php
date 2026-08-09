<?php

namespace App\Repository;

use App\Entity\ShareClaimCode;
use App\Entity\User;
use App\Service\Pagination\PaginatedResult;
use App\Service\Pagination\PaginationRequest;
use App\Service\SharingCodeFormat;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ShareClaimCode>
 */
class ShareClaimCodeRepository extends ServiceEntityRepository
{
    /** Query alias => DQL expression, for the admin table's sort control. */
    public const ADMIN_SORT_FIELDS = [
        'createdAt' => 'c.createdAt',
        'expiresAt' => 'c.expiresAt',
        'maxUses' => 'c.maxUses',
        'usesRemaining' => 'c.usesRemaining',
    ];

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
     * One page of issued codes for an administrator, newest first by default.
     *
     * Every filter is optional and every one of them narrows: an operator
     * arriving at this table after an abuse report is looking for one code
     * among everything the instance has issued, and the table grows
     * continuously between retention sweeps.
     *
     * The status filters are expressed here rather than read from a stored
     * column, because "expired" and "used up" are facts about a row and a clock
     * rather than states anything writes. A second column saying so would be
     * one more thing to keep in step.
     *
     * @param array{status?: string|null, ownerId?: int|null, createdFrom?: \DateTimeImmutable|null,
     *              createdTo?: \DateTimeImmutable|null, expiresFrom?: \DateTimeImmutable|null,
     *              expiresTo?: \DateTimeImmutable|null} $filters
     *
     * @return PaginatedResult<ShareClaimCode>
     */
    public function findAdminPage(PaginationRequest $request, array $filters = []): PaginatedResult
    {
        $qb = $this->createQueryBuilder('c')->leftJoin('c.owner', 'o');
        $now = new \DateTimeImmutable();

        if (($filters['ownerId'] ?? null) !== null) {
            $qb->andWhere('c.owner = :ownerId')->setParameter('ownerId', $filters['ownerId']);
        }

        // The owner is the only thing worth searching by. The code itself is
        // stored as a hash and cannot be searched for even by somebody holding
        // it, which is the point of hashing it.
        if ($pattern = $request->searchPattern()) {
            $qb->andWhere($qb->expr()->orX(
                'LOWER(o.name) LIKE :search',
                'LOWER(o.email) LIKE :search',
            ))->setParameter('search', $pattern);
        }

        switch ($filters['status'] ?? null) {
            case 'active':
                $qb->andWhere('c.revokedAt IS NULL')
                    ->andWhere('c.usesRemaining > 0')
                    ->andWhere('c.expiresAt > :now')
                    ->setParameter('now', $now);
                break;
            case 'withdrawn':
                $qb->andWhere('c.revokedAt IS NOT NULL');
                break;
            case 'expired':
                // Only codes that ran out of time, so an operator filtering for
                // "expired" is not shown everything that died some other way.
                $qb->andWhere('c.revokedAt IS NULL')
                    ->andWhere('c.usesRemaining > 0')
                    ->andWhere('c.expiresAt <= :now')
                    ->setParameter('now', $now);
                break;
            case 'exhausted':
                $qb->andWhere('c.revokedAt IS NULL')->andWhere('c.usesRemaining <= 0');
                break;
        }

        foreach ([
            'createdFrom' => ['c.createdAt >= :createdFrom', 'createdFrom'],
            'createdTo' => ['c.createdAt <= :createdTo', 'createdTo'],
            'expiresFrom' => ['c.expiresAt >= :expiresFrom', 'expiresFrom'],
            'expiresTo' => ['c.expiresAt <= :expiresTo', 'expiresTo'],
        ] as $key => [$expression, $parameter]) {
            if (($filters[$key] ?? null) !== null) {
                $qb->andWhere($expression)->setParameter($parameter, $filters[$key]);
            }
        }

        $total = (int) (clone $qb)->select('COUNT(c.id)')->getQuery()->getSingleScalarResult();

        $codes = $qb
            ->orderBy(self::ADMIN_SORT_FIELDS[$request->sortField], $request->direction)
            ->addOrderBy('c.id', 'DESC')
            ->setFirstResult($request->offset())
            ->setMaxResults($request->limit)
            ->getQuery()
            ->getResult();

        return PaginatedResult::fromRequest($codes, $total, $request);
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
