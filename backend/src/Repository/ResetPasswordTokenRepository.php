<?php

namespace App\Repository;

use App\Entity\ResetPasswordToken;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ResetPasswordToken>
 */
class ResetPasswordTokenRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ResetPasswordToken::class);
    }

    /**
     * Find a valid token by its token string
     */
    public function findValidToken(string $token): ?ResetPasswordToken
    {
        return $this->createQueryBuilder('t')
            ->where('t.token = :token')
            ->andWhere('t.expiresAt > :now')
            ->andWhere('t.isUsed = :isUsed')
            ->setParameter('token', $token)
            ->setParameter('now', new \DateTimeImmutable())
            ->setParameter('isUsed', false)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Invalidate all existing tokens for a user
     */
    public function invalidateAllTokensForUser(User $user): void
    {
        $this->createQueryBuilder('t')
            ->update()
            ->set('t.isUsed', ':isUsed')
            ->where('t.user = :user')
            ->setParameter('isUsed', true)
            ->setParameter('user', $user)
            ->getQuery()
            ->execute();
    }

    /**
     * Clean up expired tokens
     */
    public function removeExpiredTokens(): int
    {
        $result = $this->createQueryBuilder('t')
            ->delete()
            ->where('t.expiresAt < :now')
            ->setParameter('now', new \DateTimeImmutable())
            ->getQuery()
            ->execute();
        
        return $result;
    }
}
