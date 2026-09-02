<?php

namespace App\Repository;

use App\Entity\AdminAuditLog;
use App\Service\Pagination\ColumnFilter;
use App\Service\Pagination\PaginatedResult;
use App\Service\Pagination\PaginationRequest;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AdminAuditLog>
 */
class AdminAuditLogRepository extends ServiceEntityRepository
{
    /** Sortable columns for the audit table, as query alias => DQL field. */
    public const ADMIN_SORT_FIELDS = [
        'createdAt' => 'l.createdAt',
        'action' => 'l.action',
        'targetType' => 'l.targetType',
        'admin' => 'adminSort',
        'details' => 'l.payload',
    ];

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AdminAuditLog::class);
    }

    /**
     * One page of the audit log.
     *
     * The log used to be capped at the newest 100 rows with no way to reach
     * anything older; paging it is the only way to audit anything but today.
     *
     * @param array{createdAt?: string|null, admin?: string|null, action?: string|null,
     *               target?: string|null, details?: string|null, timezone?: string|null} $filters
     * @return PaginatedResult<AdminAuditLog>
     */
    public function findAdminPage(
        PaginationRequest $request,
        ?string $action = null,
        ?string $targetType = null,
        array $filters = [],
    ): PaginatedResult {
        $qb = $this->createQueryBuilder('l')->leftJoin('l.adminUser', 'admin');

        if ($action !== null && $action !== '') {
            $qb->andWhere('l.action = :action')->setParameter('action', $action);
        }

        if ($targetType !== null && $targetType !== '') {
            $qb->andWhere('l.targetType = :targetType')->setParameter('targetType', $targetType);
        }

        if ($pattern = $request->searchPattern()) {
            $conditions = $qb->expr()->orX(
                'LOWER(admin.name) LIKE :search',
                'LOWER(admin.email) LIKE :search',
                'LOWER(l.action) LIKE :search',
                'LOWER(l.targetType) LIKE :search',
            );

            // Target ids are integers; DQL has no portable CAST, so a numeric
            // search term is matched exactly rather than as a substring.
            if (ctype_digit($request->search)) {
                $conditions->add('l.targetId = :targetId');
                $qb->setParameter('targetId', (int) $request->search);
            }

            $qb->andWhere($conditions)->setParameter('search', $pattern);
        }

        ColumnFilter::applyDay($qb, 'l.createdAt', 'filterCreatedAt', $filters['createdAt'] ?? null, $filters['timezone'] ?? null);

        if ($pattern = ColumnFilter::pattern($filters['admin'] ?? null)) {
            $qb->andWhere($qb->expr()->orX(
                'LOWER(admin.name) LIKE :filterAdmin',
                'LOWER(admin.email) LIKE :filterAdmin',
            ))->setParameter('filterAdmin', $pattern);
        }

        if ($pattern = ColumnFilter::pattern($filters['action'] ?? null)) {
            $qb->andWhere('LOWER(l.action) LIKE :filterAction')->setParameter('filterAction', $pattern);
        }

        $target = ColumnFilter::text($filters['target'] ?? null);
        if ($target !== '') {
            if (ctype_digit($target)) {
                $qb->andWhere('l.targetId = :filterTargetId')->setParameter('filterTargetId', (int) $target);
            } else {
                $qb->andWhere('LOWER(l.targetType) LIKE :filterTarget')
                    ->setParameter('filterTarget', ColumnFilter::pattern($target));
            }
        }

        if ($pattern = ColumnFilter::pattern($filters['details'] ?? null)) {
            $qb->andWhere('LOWER(l.payload) LIKE :filterDetails')->setParameter('filterDetails', $pattern);
        }

        $total = (int) (clone $qb)->select('COUNT(l.id)')
            ->getQuery()
            ->getSingleScalarResult();

        if ($request->sortField === 'admin') {
            $qb->addSelect('COALESCE(admin.name, admin.email) AS HIDDEN adminSort');
        }

        $logs = $qb
            ->orderBy(self::ADMIN_SORT_FIELDS[$request->sortField], $request->direction)
            ->addOrderBy('l.id', 'DESC')
            ->setFirstResult($request->offset())
            ->setMaxResults($request->limit)
            ->getQuery()
            ->getResult();

        return PaginatedResult::fromRequest($logs, $total, $request);
    }

    /**
     * The distinct actions and target types present in the log, for the filter
     * dropdowns.
     *
     * @return array{actions: list<string>, targetTypes: list<string>}
     */
    public function findFilterOptions(): array
    {
        $read = function (string $field): array {
            $rows = $this->createQueryBuilder('l')
                ->select(sprintf('DISTINCT l.%s AS value', $field))
                ->orderBy(sprintf('l.%s', $field), 'ASC')
                ->getQuery()
                ->getScalarResult();

            return array_values(array_filter(array_map(
                static fn (array $row): string => (string) $row['value'],
                $rows
            )));
        };

        return ['actions' => $read('action'), 'targetTypes' => $read('targetType')];
    }
}
