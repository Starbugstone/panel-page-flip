<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\User;
use App\Entity\UserWarning;
use App\Service\Pagination\PaginatedResult;
use App\Service\Pagination\PaginationRequest;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<UserWarning>
 */
class UserWarningRepository extends ServiceEntityRepository
{
    /** Query alias => DQL expression, for the admin table's sort control. */
    public const ADMIN_SORT_FIELDS = [
        'createdAt' => 'w.createdAt',
        'acknowledgedAt' => 'w.acknowledgedAt',
    ];

    /**
     * How many unacknowledged warnings one account may be shown at once.
     *
     * A ceiling rather than a rule about issuing them: an administrator may
     * send as many as they need, but a session that has to render four hundred
     * banners is a session that renders none of them usefully.
     */
    public const MAX_OPEN_PER_RECIPIENT = 20;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UserWarning::class);
    }

    /**
     * The warnings this account has not dismissed yet, oldest first.
     *
     * Oldest first because they are read in order and dismissed one at a time;
     * a newer warning jumping the queue would leave the older one for last,
     * which is the wrong way round for a sequence of escalating notices.
     *
     * @return list<UserWarning>
     */
    public function findOpenFor(User $recipient): array
    {
        return $this->createQueryBuilder('w')
            ->andWhere('w.recipient = :recipient')
            ->andWhere('w.acknowledgedAt IS NULL')
            ->setParameter('recipient', $recipient)
            ->orderBy('w.createdAt', 'ASC')
            ->addOrderBy('w.id', 'ASC')
            ->setMaxResults(self::MAX_OPEN_PER_RECIPIENT)
            ->getQuery()
            ->getResult();
    }

    /**
     * One page of warnings for the administrative table.
     *
     * @param array{recipientId?: int|null, openOnly?: bool} $filters
     *
     * @return PaginatedResult<UserWarning>
     */
    public function findAdminPage(PaginationRequest $request, array $filters = []): PaginatedResult
    {
        $qb = $this->createQueryBuilder('w')->leftJoin('w.recipient', 'r');

        if (($filters['recipientId'] ?? null) !== null) {
            $qb->andWhere('w.recipient = :recipientId')->setParameter('recipientId', $filters['recipientId']);
        }

        if (($filters['openOnly'] ?? false) === true) {
            $qb->andWhere('w.acknowledgedAt IS NULL');
        }

        if ($pattern = $request->searchPattern()) {
            $qb->andWhere($qb->expr()->orX(
                'LOWER(r.name) LIKE :search',
                'LOWER(r.email) LIKE :search',
                'LOWER(w.subjectLabel) LIKE :search',
            ))->setParameter('search', $pattern);
        }

        $total = (int) (clone $qb)->select('COUNT(w.id)')->getQuery()->getSingleScalarResult();

        $warnings = $qb
            ->orderBy(self::ADMIN_SORT_FIELDS[$request->sortField], $request->direction)
            ->addOrderBy('w.id', 'DESC')
            ->setFirstResult($request->offset())
            ->setMaxResults($request->limit)
            ->getQuery()
            ->getResult();

        return PaginatedResult::fromRequest($warnings, $total, $request);
    }
}
