<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\User;
use App\Entity\UserMetadataCredential;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<UserMetadataCredential>
 */
class UserMetadataCredentialRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UserMetadataCredential::class);
    }

    public function findForUser(User $user): ?UserMetadataCredential
    {
        return $this->findOneBy(['user' => $user]);
    }

    /**
     * Which of these users have a credential, in one query.
     *
     * The admin list renders a page of users at a time, and asking per row is
     * how a list view quietly becomes fifty queries.
     *
     * @param list<int> $userIds
     * @return array<int, true>
     */
    public function findUserIdsWithCredential(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        $rows = $this->createQueryBuilder('c')
            ->select('IDENTITY(c.user) AS userId')
            ->where('c.user IN (:ids)')
            ->setParameter('ids', $userIds)
            ->getQuery()
            ->getScalarResult();

        $present = [];
        foreach ($rows as $row) {
            $present[(int) $row['userId']] = true;
        }

        return $present;
    }
}
