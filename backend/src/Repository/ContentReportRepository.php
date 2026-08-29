<?php

namespace App\Repository;

use App\Entity\ContentReport;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<ContentReport> */
final class ContentReportRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ContentReport::class);
    }

    /** @return list<ContentReport> */
    public function findForAdmin(?string $status, ?string $category, ?\DateTimeImmutable $from, ?\DateTimeImmutable $to): array
    {
        $qb = $this->createQueryBuilder('r')
            // shareComic as well as linkedComic: the queue labels a share by its
            // comic's title, and a report whose share is linked without one —
            // rows predating the target migration — would otherwise cost a query
            // per row to render one column.
            ->addSelect('admin', 'linkedUser', 'linkedComic', 'linkedShare', 'shareComic')
            ->leftJoin('r.reviewedByAdmin', 'admin')
            ->leftJoin('r.linkedUser', 'linkedUser')
            ->leftJoin('r.linkedComic', 'linkedComic')
            ->leftJoin('r.linkedShare', 'linkedShare')
            ->leftJoin('linkedShare.comic', 'shareComic')
            ->orderBy('r.createdAt', 'DESC')
            ->setMaxResults(250);

        if ($status !== null && $status !== '') {
            $qb->andWhere('r.status = :status')->setParameter('status', $status);
        }
        if ($category !== null && $category !== '') {
            $qb->andWhere('r.category = :category')->setParameter('category', $category);
        }
        if ($from !== null) {
            $qb->andWhere('r.createdAt >= :from')->setParameter('from', $from);
        }
        if ($to !== null) {
            $qb->andWhere('r.createdAt < :to')->setParameter('to', $to->modify('+1 day'));
        }

        return $qb->getQuery()->getResult();
    }

    /** @return list<ContentReport> */
    public function findExpiredClosed(\DateTimeImmutable $before, int $limit = 100): array
    {
        return $this->createQueryBuilder('r')
            ->andWhere('r.status IN (:closed)')
            ->andWhere('r.updatedAt < :before')
            ->andWhere('r.legalHold = false')
            ->setParameter('closed', [ContentReport::STATUS_REJECTED, ContentReport::STATUS_CLOSED])
            ->setParameter('before', $before)
            ->orderBy('r.updatedAt', 'ASC')
            ->setMaxResults(max(1, min($limit, 1000)))
            ->getQuery()
            ->getResult();
    }
}
