<?php

namespace App\Repository;

use App\Entity\Comic;
use App\Entity\ComicShare;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ComicShare>
 *
 * @method ComicShare|null find($id, $lockMode = null, $lockVersion = null)
 * @method ComicShare|null findOneBy(array $criteria, array $orderBy = null)
 * @method ComicShare[]    findAll()
 * @method ComicShare[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class ComicShareRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ComicShare::class);
    }

    /**
     * The single relationship between a comic and a recipient, whatever state
     * it is in. Inviting somebody who has already been invited reuses this row
     * rather than adding a second one.
     */
    public function findForComicAndRecipient(Comic $comic, string $email): ?ComicShare
    {
        return $this->findOneBy([
            'comic' => $comic,
            'recipientEmailNormalized' => ComicShare::normaliseEmail($email),
        ]);
    }

    /**
     * The share granting this user access to this comic, if any.
     *
     * Matched on the email rather than the recipient user so an invitation sent
     * before the recipient registered still resolves once they sign in — and so
     * a user who changes their email address keeps the shares they accepted.
     *
     * @return ComicShare|null
     */
    public function findAccessFor(User $user, Comic $comic): ?ComicShare
    {
        return $this->recipientQueryBuilder($user)
            ->andWhere('s.comic = :comic')
            ->andWhere('s.status = :accepted')
            ->andWhere('s.unavailableAt IS NULL')
            ->setParameter('comic', $comic)
            ->setParameter('accepted', ComicShare::STATUS_ACCEPTED)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Every share this user has accepted and not hidden — the shared half of
     * their collection.
     *
     * @return list<ComicShare>
     */
    public function findVisibleCollectionShares(User $user): array
    {
        return $this->recipientQueryBuilder($user)
            ->andWhere('s.status = :accepted')
            ->andWhere('s.unavailableAt IS NULL')
            ->andWhere('s.comic IS NOT NULL')
            ->andWhere('s.recipientRemovedAt IS NULL')
            ->setParameter('accepted', ComicShare::STATUS_ACCEPTED)
            ->addSelect('c', 'o')
            ->leftJoin('s.comic', 'c')
            ->leftJoin('s.owner', 'o')
            ->getQuery()
            ->getResult();
    }

    /**
     * Everything the "Shared with me" tab shows: pending invitations, accepted
     * shares, ones the recipient hid, and tombstones.
     *
     * @return list<ComicShare>
     */
    public function findAllForRecipient(User $user): array
    {
        return $this->recipientQueryBuilder($user)
            ->addSelect('c', 'o')
            ->leftJoin('s.comic', 'c')
            ->leftJoin('s.owner', 'o')
            ->orderBy('s.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Every share the user has handed out, newest comic first.
     *
     * @return list<ComicShare>
     */
    public function findAllForOwner(User $user): array
    {
        return $this->createQueryBuilder('s')
            ->addSelect('c')
            ->leftJoin('s.comic', 'c')
            ->andWhere('s.owner = :owner')
            ->setParameter('owner', $user)
            ->orderBy('s.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Shares on this comic that still mean something to their recipient, i.e.
     * the ones a deletion has to tombstone and warn about.
     *
     * @return list<ComicShare>
     */
    public function findLiveSharesForComic(Comic $comic): array
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.comic = :comic')
            ->andWhere('s.unavailableAt IS NULL')
            ->andWhere('s.status IN (:live)')
            ->setParameter('comic', $comic)
            ->setParameter('live', [ComicShare::STATUS_PENDING, ComicShare::STATUS_ACCEPTED])
            ->getQuery()
            ->getResult();
    }

    /**
     * How many people each of these comics is actively shared with, indexed by
     * comic id. Batched because the library endpoint needs it for every card.
     *
     * @param list<Comic> $comics
     * @return array<int, int>
     */
    public function countActiveSharesByComic(array $comics): array
    {
        if ($comics === []) {
            return [];
        }

        $rows = $this->createQueryBuilder('s')
            ->select('IDENTITY(s.comic) AS comicId', 'COUNT(s.id) AS total')
            ->andWhere('s.comic IN (:comics)')
            ->andWhere('s.unavailableAt IS NULL')
            ->andWhere('s.status IN (:live)')
            ->setParameter('comics', $comics)
            ->setParameter('live', [ComicShare::STATUS_PENDING, ComicShare::STATUS_ACCEPTED])
            ->groupBy('s.comic')
            ->getQuery()
            ->getArrayResult();

        $counts = [];
        foreach ($rows as $row) {
            $counts[(int) $row['comicId']] = (int) $row['total'];
        }

        return $counts;
    }

    /**
     * The shares behind these comics for this viewer, indexed by comic id, so
     * serializing a library does not ask one question per card.
     *
     * @param list<Comic> $comics
     * @return array<int, ComicShare>
     */
    public function findAccessIndexedByComic(User $user, array $comics): array
    {
        if ($comics === []) {
            return [];
        }

        $shares = $this->recipientQueryBuilder($user)
            ->addSelect('o')
            ->leftJoin('s.owner', 'o')
            ->andWhere('s.comic IN (:comics)')
            ->andWhere('s.status = :accepted')
            ->andWhere('s.unavailableAt IS NULL')
            ->setParameter('comics', $comics)
            ->setParameter('accepted', ComicShare::STATUS_ACCEPTED)
            ->getQuery()
            ->getResult();

        $byComic = [];
        foreach ($shares as $share) {
            $comicId = $share->getComic()?->getId();
            if ($comicId !== null) {
                $byComic[$comicId] = $share;
            }
        }

        return $byComic;
    }

    /** Invitations awaiting this user's answer, for the navigation badge. */
    public function countPendingForRecipient(User $user): int
    {
        return (int) $this->recipientQueryBuilder($user)
            ->select('COUNT(s.id)')
            ->andWhere('s.status = :pending')
            ->andWhere('s.unavailableAt IS NULL')
            ->andWhere('s.comic IS NOT NULL')
            ->andWhere('s.expiresAt IS NULL OR s.expiresAt > :now')
            ->setParameter('pending', ComicShare::STATUS_PENDING)
            ->setParameter('now', new \DateTimeImmutable())
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Tombstones and dead ends belonging to this recipient — what "Remove all
     * dead shares" clears. Revoked and declined rows count as dead: nothing
     * about them can come back without a fresh invitation, which would create
     * its own row state anyway.
     *
     * @return list<ComicShare>
     */
    public function findDeadSharesForRecipient(User $user): array
    {
        return $this->recipientQueryBuilder($user)
            ->andWhere('s.unavailableAt IS NOT NULL OR s.comic IS NULL OR s.status IN (:dead)')
            ->setParameter('dead', [ComicShare::STATUS_REVOKED, ComicShare::STATUS_DECLINED])
            ->getQuery()
            ->getResult();
    }

    /** How many invitations this user has sent since a given moment. */
    public function countInvitationsSentSince(User $user, \DateTimeInterface $since): int
    {
        return (int) $this->createQueryBuilder('s')
            ->select('COUNT(t.id)')
            ->join('s.invitationTokens', 't')
            ->andWhere('s.owner = :owner')
            ->andWhere('t.createdAt >= :since')
            ->setParameter('owner', $user)
            ->setParameter('since', $since)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Pending invitations whose window has closed. Nothing can be done with
     * them any more and they hold a non-user's email address, so they are
     * deleted rather than kept as history.
     *
     * @return list<ComicShare>
     */
    public function findExpiredPendingShares(\DateTimeInterface $now): array
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.status = :pending')
            ->andWhere('s.expiresAt IS NOT NULL')
            ->andWhere('s.expiresAt < :now')
            ->setParameter('pending', ComicShare::STATUS_PENDING)
            ->setParameter('now', $now)
            ->getQuery()
            ->getResult();
    }

    /**
     * Everything touching this user, from either side. Used when erasing an
     * account.
     *
     * @return list<ComicShare>
     */
    public function findAllInvolving(User $user): array
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.owner = :user OR s.recipientUser = :user OR s.recipientEmailNormalized = :email')
            ->setParameter('user', $user)
            ->setParameter('email', ComicShare::normaliseEmail((string) $user->getEmail()))
            ->getQuery()
            ->getResult();
    }

    /**
     * Matching a recipient by user *or* email is the rule everywhere, because a
     * share created before the recipient registered has no user attached yet.
     */
    private function recipientQueryBuilder(User $user): \Doctrine\ORM\QueryBuilder
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.recipientUser = :user OR s.recipientEmailNormalized = :email')
            ->setParameter('user', $user)
            ->setParameter('email', ComicShare::normaliseEmail((string) $user->getEmail()));
    }
}
