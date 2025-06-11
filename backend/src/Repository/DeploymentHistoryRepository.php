<?php

namespace App\Repository;

use App\Entity\DeploymentHistory;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DeploymentHistory>
 */
class DeploymentHistoryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DeploymentHistory::class);
    }

    /**
     * Get the last N successful deployments
     */
    public function getLastSuccessfulDeployments(int $limit = 10): array
    {
        return $this->createQueryBuilder('d')
            ->where('d.status = :status')
            ->setParameter('status', 'success')
            ->orderBy('d.deployedAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Get the current active deployment (last successful)
     */
    public function getCurrentDeployment(): ?DeploymentHistory
    {
        return $this->createQueryBuilder('d')
            ->where('d.status = :status')
            ->setParameter('status', 'success')
            ->orderBy('d.deployedAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Get deployment by commit hash
     */
    public function findByCommitHash(string $commitHash): ?DeploymentHistory
    {
        return $this->createQueryBuilder('d')
            ->where('d.commitHash = :hash')
            ->setParameter('hash', $commitHash)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Get deployment history with pagination
     */
    public function getDeploymentHistory(int $page = 1, int $limit = 20): array
    {
        $offset = ($page - 1) * $limit;
        
        return $this->createQueryBuilder('d')
            ->orderBy('d.deployedAt', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Count total deployments
     */
    public function countDeployments(): int
    {
        return $this->createQueryBuilder('d')
            ->select('COUNT(d.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Clean up old deployment records (keep last 50)
     */
    public function cleanupOldDeployments(int $keepCount = 50): int
    {
        $deploymentsToKeep = $this->createQueryBuilder('d')
            ->select('d.id')
            ->orderBy('d.deployedAt', 'DESC')
            ->setMaxResults($keepCount)
            ->getQuery()
            ->getArrayResult();

        if (empty($deploymentsToKeep)) {
            return 0;
        }

        $idsToKeep = array_column($deploymentsToKeep, 'id');

        return $this->createQueryBuilder('d')
            ->delete()
            ->where('d.id NOT IN (:ids)')
            ->setParameter('ids', $idsToKeep)
            ->getQuery()
            ->execute();
    }
} 