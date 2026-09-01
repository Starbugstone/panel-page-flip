<?php

namespace App\Repository;

use App\Entity\Comic;
use App\Entity\ShareClaimCode;
use App\Entity\User;
use App\Enum\ShareCodeType;
use App\Service\Pagination\ColumnFilter;
use App\Service\Pagination\PaginatedResult;
use App\Service\Pagination\PaginationRequest;
use App\Service\ParsedShareCode;
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
        'id' => 'c.id',
        'owner' => 'o.email',
        'comicCount' => 'SIZE(c.comics)',
        'timesUsed' => 'timesUsedSort',
        'status' => 'statusSort',
    ];

    /**
     * The status filter's values, and the label the Status column shows for
     * each. The dropdown sends a value and the column filter is typed against a
     * label, so both have to come from here or they drift apart.
     */
    public const ADMIN_STATUS_LABELS = [
        'active' => 'Active',
        'comics_removed' => 'Comics removed',
        'withdrawn' => 'Withdrawn',
        'expired' => 'Expired',
        'exhausted' => 'Used up',
    ];

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ShareClaimCode::class);
    }

    /**
     * Find a code by what somebody typed, once it has been recognised.
     *
     * By hash, so the plaintext is never compared against anything stored and a
     * database leak yields nothing redeemable. The hash covers the type as well
     * as the token, so a `C-` code cannot find the `G-` row that drew the same
     * twelve characters.
     *
     * Returns whatever the hash finds, live or not — deciding whether it may
     * still be used belongs to the service, which has to answer identically for
     * a spent code and a code that never existed.
     */
    public function findByParsedCode(ParsedShareCode $code): ?ShareClaimCode
    {
        return $this->findOneBy(['codeHash' => $code->hash()]);
    }

    /**
     * Whether any content code was minted from this token, of either type.
     *
     * Asked by the allocator, which keeps one visible token from meaning two
     * things at once. That is not what makes the three kinds unambiguous — the
     * prefix does that — but it is what makes a code safe to read aloud.
     */
    public function existsForToken(string $token): bool
    {
        $hashes = array_map(
            static fn (ShareCodeType $type): string => SharingCodeFormat::hash($type, $token),
            [ShareCodeType::COMIC, ShareCodeType::GROUP]
        );

        return (int) $this->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->andWhere('c.codeHash IN (:hashes)')
            ->setParameter('hashes', $hashes)
            ->getQuery()
            ->getSingleScalarResult() > 0;
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
     *              expiresTo?: \DateTimeImmutable|null, id?: string|null, owner?: string|null,
     *              comics?: string|null, uses?: string|null, columnStatus?: string|null,
     *              columnCreatedAt?: \DateTimeImmutable|null, columnCreatedTo?: \DateTimeImmutable|null,
     *              columnExpiresAt?: \DateTimeImmutable|null, columnExpiresTo?: \DateTimeImmutable|null,
     *              deletedAfter?: \DateTimeImmutable|null} $filters
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

        $filterId = ColumnFilter::text($filters['id'] ?? null);
        if (ctype_digit($filterId)) {
            $qb->andWhere('c.id = :filterId')->setParameter('filterId', (int) $filterId);
        }

        if ($pattern = ColumnFilter::pattern($filters['owner'] ?? null)) {
            $qb->andWhere($qb->expr()->orX(
                'LOWER(o.name) LIKE :filterOwner',
                'LOWER(o.email) LIKE :filterOwner',
            ))->setParameter('filterOwner', $pattern);
        }

        $filterComics = ColumnFilter::text($filters['comics'] ?? null);
        if (ctype_digit($filterComics)) {
            $qb->andWhere('SIZE(c.comics) = :filterComicCount')->setParameter('filterComicCount', (int) $filterComics);
        } elseif ($filterComics !== '') {
            $comicSubquery = $this->getEntityManager()->createQueryBuilder()
                ->select('1')
                ->from(Comic::class, 'filterCodeComic')
                ->where('filterCodeComic MEMBER OF c.comics')
                ->andWhere('LOWER(filterCodeComic.title) LIKE :filterComics')
                ->getDQL();
            $qb->andWhere($qb->expr()->exists($comicSubquery))
                ->setParameter('filterComics', ColumnFilter::pattern($filterComics));
        }

        $filterUses = ColumnFilter::text($filters['uses'] ?? null);
        if (preg_match('/^(\d+)\s*\/\s*(\d+)$/', $filterUses, $matches)) {
            $qb->andWhere('(c.maxUses - c.usesRemaining) = :filterTimesUsed')
                ->andWhere('c.maxUses = :filterMaxUses')
                ->setParameter('filterTimesUsed', (int) $matches[1])
                ->setParameter('filterMaxUses', (int) $matches[2]);
        } elseif (ctype_digit($filterUses)) {
            $qb->andWhere('(c.maxUses - c.usesRemaining) = :filterTimesUsed')
                ->setParameter('filterTimesUsed', (int) $filterUses);
        }

        $status = $filters['status']
            ?? ColumnFilter::matchLabel($qb, $filters['columnStatus'] ?? null, self::ADMIN_STATUS_LABELS);

        switch ($status) {
            case 'active':
                $qb->andWhere('c.revokedAt IS NULL')
                    ->andWhere('c.usesRemaining > 0')
                    ->andWhere('c.expiresAt > :now')
                    // A code whose package has lost a comic cannot be redeemed
                    // — a group is handed over whole or not at all — so listing
                    // it as active tells an operator it works when it does not.
                    ->andWhere('SIZE(c.comics) = c.issuedComicCount')
                    ->setParameter('now', $now);
                break;
            case 'comics_removed':
                // Live in every other respect and still unredeemable, which is
                // the one dead state an owner cannot see coming. Findable so an
                // operator can withdraw it and tell them to reissue.
                $qb->andWhere('c.revokedAt IS NULL')
                    ->andWhere('c.usesRemaining > 0')
                    ->andWhere('c.expiresAt > :now')
                    ->andWhere('SIZE(c.comics) <> c.issuedComicCount')
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

        foreach ([
            'columnCreatedAt' => ['c.createdAt >= :columnCreatedAt', 'columnCreatedAt'],
            'columnCreatedTo' => ['c.createdAt <= :columnCreatedTo', 'columnCreatedTo'],
            'columnExpiresAt' => ['c.expiresAt >= :columnExpiresAt', 'columnExpiresAt'],
            'columnExpiresTo' => ['c.expiresAt <= :columnExpiresTo', 'columnExpiresTo'],
        ] as $key => [$expression, $parameter]) {
            if (($filters[$key] ?? null) !== null) {
                $qb->andWhere($expression)->setParameter($parameter, $filters[$key]);
            }
        }

        if (($filters['deletedAfter'] ?? null) instanceof \DateTimeImmutable) {
            $expiryDay = $filters['deletedAfter']->modify('-' . ltrim(ShareClaimCode::RETENTION_AFTER_EXPIRY, '+'));
            $qb->andWhere('c.expiresAt >= :deletedAfterFrom')
                ->andWhere('c.expiresAt < :deletedAfterTo')
                ->setParameter('deletedAfterFrom', $expiryDay)
                ->setParameter('deletedAfterTo', $expiryDay->modify('+1 day'));
        }

        $total = (int) (clone $qb)->select('COUNT(c.id)')->getQuery()->getSingleScalarResult();

        if ($request->sortField === 'timesUsed') {
            $qb->addSelect('(c.maxUses - c.usesRemaining) AS HIDDEN timesUsedSort');
        }
        if ($request->sortField === 'status') {
            $qb->addSelect(sprintf(
                'CASE WHEN c.revokedAt IS NOT NULL THEN 4 WHEN c.usesRemaining <= 0 THEN 3 WHEN c.expiresAt <= CURRENT_TIMESTAMP() THEN 2 WHEN SIZE(c.comics) <> c.issuedComicCount THEN 1 ELSE 0 END AS HIDDEN statusSort'
            ));
        }

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
