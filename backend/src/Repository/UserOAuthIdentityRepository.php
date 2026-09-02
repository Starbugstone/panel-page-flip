<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\User;
use App\Entity\UserOAuthIdentity;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<UserOAuthIdentity> */
final class UserOAuthIdentityRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UserOAuthIdentity::class);
    }

    public function findIdentity(string $provider, string $subject): ?UserOAuthIdentity
    {
        return $this->findOneBy([
            'provider' => strtolower($provider),
            'providerSubject' => $subject,
        ]);
    }

    public function findForUser(User $user, string $provider): ?UserOAuthIdentity
    {
        return $this->findOneBy([
            'user' => $user,
            'provider' => strtolower($provider),
        ]);
    }
}
