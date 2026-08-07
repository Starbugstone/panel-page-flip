<?php

namespace App\Repository;

use App\Entity\ShareInvitationToken;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ShareInvitationToken>
 *
 * @method ShareInvitationToken|null find($id, $lockMode = null, $lockVersion = null)
 * @method ShareInvitationToken|null findOneBy(array $criteria, array $orderBy = null)
 * @method ShareInvitationToken[]    findAll()
 * @method ShareInvitationToken[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
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
