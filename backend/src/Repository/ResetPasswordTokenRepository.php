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
        $tokenHash = hash('sha256', $token);

        return $this->createQueryBuilder('t')
            ->where('t.token = :token')
            ->andWhere('t.expiresAt > :now')
            ->andWhere('t.isUsed = :isUsed')
            ->setParameter('token', $tokenHash)
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
     * Revoke every outstanding link alongside a password change.
     *
     * Marks the managed tokens rather than writing straight away, so the
     * revocation commits in the same flush as the new password: neither can
     * land without the other. A user who has never been saved has no tokens.
     */
    public function markOutstandingTokensUsed(User $user): void
    {
        if ($user->getId() === null) {
            return;
        }

        foreach ($this->findBy(['user' => $user, 'isUsed' => false]) as $token) {
            $token->setIsUsed(true);
        }
    }

    /**
     * Claim one token for its single use.
     *
     * The conditional write is the check that counts: a token read as valid may
     * since have been spent or revoked by a request that got there first.
     *
     * @return bool whether this caller is the one that spent it
     */
    public function claimToken(ResetPasswordToken $token): bool
    {
        return $this->createQueryBuilder('t')
            ->update()
            ->set('t.isUsed', ':used')
            ->where('t.id = :id')
            ->andWhere('t.isUsed = :unused')
            ->setParameter('used', true)
            ->setParameter('id', $token->getId())
            ->setParameter('unused', false)
            ->getQuery()
            ->execute() === 1;
    }
}
