<?php

namespace App\Repository;

use App\Entity\ShareInvitationToken;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ShareInvitationToken>
 */
class ShareInvitationTokenRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ShareInvitationToken::class);
    }

    /**
     * Resolve the plaintext token from an invitation link.
     *
     * The lookup is by hash, so the plaintext is never compared against stored
     * data and never has to be written anywhere.
     */
    public function findByPlaintext(string $plaintext): ?ShareInvitationToken
    {
        if ($plaintext === '') {
            return null;
        }

        return $this->findOneBy(['tokenHash' => ShareInvitationToken::hash($plaintext)]);
    }
}
