<?php

namespace App\Repository;

use App\Entity\Comic;
use App\Entity\ComicShare;
use App\Entity\User;
use App\Service\Pagination\PaginatedResult;
use App\Service\Pagination\PaginationRequest;
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
    /** Query alias => DQL expression, for the admin table's sort control. */
    public const ADMIN_SORT_FIELDS = [
        'createdAt' => 's.createdAt',
        'status' => 's.status',
        'comicTitle' => 'c.title',
    ];

    /** The statuses the admin table may filter on. */
    public const ADMIN_STATUSES = [
        ComicShare::STATUS_PENDING,
        ComicShare::STATUS_ACCEPTED,
        ComicShare::STATUS_DECLINED,
        ComicShare::STATUS_REVOKED,
    ];

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
     * Existing relationships for one recipient across a bulk offer, indexed by
     * comic id so a large folder does not perform one lookup per comic.
     *
     * @param list<Comic> $comics
     * @return array<int, ComicShare>
     */
    public function findForComicsAndRecipient(array $comics, string $email): array
    {
        if ($comics === []) {
            return [];
        }

        $shares = $this->createQueryBuilder('s')
            ->andWhere('s.comic IN (:comics)')
            ->andWhere('s.recipientEmailNormalized = :email')
            ->setParameter('comics', $comics)
            ->setParameter('email', ComicShare::normaliseEmail($email))
            ->getQuery()
            ->getResult();

        $indexed = [];
        foreach ($shares as $share) {
            $comicId = $share->getComic()?->getId();
            if ($comicId !== null) {
                $indexed[(int) $comicId] = $share;
            }
        }

        return $indexed;
    }

    /**
     * Every per-comic grant carried by one folder invitation.
     *
     * @return list<ComicShare>
     */
    public function findInvitationBatch(ComicShare $anchor): array
    {
        $batchId = $anchor->getInvitationBatchId();
        if ($batchId === null) {
            return [$anchor];
        }

        return $this->createQueryBuilder('s')
            ->addSelect('c', 'o')
            ->leftJoin('s.comic', 'c')
            ->leftJoin('s.owner', 'o')
            ->andWhere('s.invitationBatchId = :batchId')
            ->setParameter('batchId', $batchId)
            ->orderBy('s.id', 'ASC')
            ->getQuery()
            ->getResult();
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
        return $this->readableQueryBuilder($user)
            ->andWhere('s.comic = :comic')
            ->setParameter('comic', $comic)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * The comics in the shared half of this user's collection, as ids.
     *
     * Ids rather than entities because that is all the library query wants: it
     * folds them into one `WHERE owner = ? OR id IN (…)` and re-reads the comics
     * itself. Hydrating a share, its comic and its owner per row — three objects
     * apiece, thrown away immediately — was the most expensive thing the
     * dashboard did before it had loaded anything a reader can see.
     *
     * @return list<int>
     */
    public function findVisibleCollectionComicIds(User $user): array
    {
        $rows = $this->readableQueryBuilder($user)
            ->select('IDENTITY(s.comic) AS comicId')
            ->andWhere('s.recipientRemovedAt IS NULL')
            ->getQuery()
            ->getScalarResult();

        return array_map(static fn ($id): int => (int) $id, array_column($rows, 'comicId'));
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
     * Every share the user has handed out that still refers to a comic they
     * have, newest first.
     *
     * Tombstones are deliberately excluded. They exist to explain a
     * disappearance to the people who lost access; the owner is the one who
     * caused it, already knows, and has no comic left to manage — so a deleted
     * comic leaves their sharing list entirely.
     *
     * @return list<ComicShare>
     */
    public function findAllForOwner(User $user): array
    {
        return $this->createQueryBuilder('s')
            ->addSelect('c')
            ->leftJoin('s.comic', 'c')
            ->andWhere('s.owner = :owner')
            ->andWhere('s.comic IS NOT NULL')
            ->andWhere('s.unavailableAt IS NULL')
            ->setParameter('owner', $user)
            ->orderBy('s.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Everything the user has handed out, tombstones included. Only for the
     * personal-data export, which describes what is stored rather than what the
     * sharing page shows.
     *
     * @return list<ComicShare>
     */
    public function findAllForOwnerIncludingTombstones(User $user): array
    {
        return $this->createQueryBuilder('s')
            // Joined because the export reads the comic off every row. Left, not
            // inner: a tombstone has no comic left, and it is precisely the rows
            // that lost theirs that an export still has to describe.
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
        return $this->liveSharesForComicQueryBuilder($comic)
            ->getQuery()
            ->getResult();
    }

    /**
     * The live shares on this comic whose recipient has confirmed their age —
     * the only ones re-gating has anything to do.
     *
     * Narrowed in the query rather than after loading, so a comic shared with
     * fifty people and confirmed by three hydrates three rows. That is where
     * the cost of re-gating actually is; going round the ORM to save the rest
     * would cost the identity-map consistency the callers rely on.
     *
     * @return list<ComicShare>
     */
    public function findConfirmedSharesForComic(Comic $comic): array
    {
        return $this->liveSharesForComicQueryBuilder($comic)
            ->andWhere('s.adultConfirmedAt IS NOT NULL')
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

        $shares = $this->readableQueryBuilder($user)
            ->addSelect('o')
            ->leftJoin('s.owner', 'o')
            ->andWhere('s.comic IN (:comics)')
            ->setParameter('comics', $comics)
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
        $rows = $this->recipientQueryBuilder($user)
            ->select('s.id', 's.invitationBatchId')
            ->andWhere('s.status = :pending')
            ->andWhere('s.unavailableAt IS NULL')
            ->andWhere('s.comic IS NOT NULL')
            ->andWhere('s.expiresAt IS NULL OR s.expiresAt > :now')
            ->setParameter('pending', ComicShare::STATUS_PENDING)
            ->setParameter('now', new \DateTimeImmutable())
            ->getQuery()
            ->getArrayResult();

        $invitations = [];
        foreach ($rows as $row) {
            $key = $row['invitationBatchId'] === null
                ? 'share:'.$row['id']
                : 'batch:'.$row['invitationBatchId'];
            $invitations[$key] = true;
        }

        return count($invitations);
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
        return $this->deadSharesQueryBuilder($user)
            ->getQuery()
            ->getResult();
    }

    /**
     * The same set, counted rather than hydrated. The summary endpoint runs on
     * every authenticated page load, so loading a recipient's whole tombstone
     * history just to call count() on it gets more expensive the longer they
     * leave it uncleared.
     */
    public function countDeadSharesForRecipient(User $user): int
    {
        return (int) $this->deadSharesQueryBuilder($user)
            ->select('COUNT(s.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** Counterpart to {@see findLiveSharesForComic()} for the deletion warning. */
    public function countLiveSharesForComic(Comic $comic): int
    {
        return (int) $this->liveSharesForComicQueryBuilder($comic)
            ->select('COUNT(s.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * "A share on this comic that still means something to its recipient",
     * aliased `s` — pending or accepted, and not tombstoned.
     *
     * One definition, because deleting a comic, stopping sharing, warning the
     * owner how many people that affects and re-gating all have to agree on
     * which shares they are talking about.
     */
    private function liveSharesForComicQueryBuilder(Comic $comic): \Doctrine\ORM\QueryBuilder
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.comic = :comic')
            ->andWhere('s.unavailableAt IS NULL')
            ->andWhere('s.status IN (:live)')
            ->setParameter('comic', $comic)
            ->setParameter('live', [ComicShare::STATUS_PENDING, ComicShare::STATUS_ACCEPTED]);
    }

    private function deadSharesQueryBuilder(User $user): \Doctrine\ORM\QueryBuilder
    {
        return $this->recipientQueryBuilder($user)
            ->andWhere('s.unavailableAt IS NOT NULL OR s.comic IS NULL OR s.status IN (:dead)')
            ->setParameter('dead', [ComicShare::STATUS_REVOKED, ComicShare::STATUS_DECLINED]);
    }

    /**
     * Pending invitations whose window has closed. Nothing can be done with
     * them any more and they hold a non-user's email address, so they are
     * deleted rather than kept as history.
     *
     * @param int|null $limit bound the batch so a long-neglected backlog is not
     *                        hydrated in one go
     * @return list<ComicShare>
     */
    public function findExpiredPendingShares(\DateTimeInterface $now, ?int $limit = null): array
    {
        $qb = $this->createQueryBuilder('s')
            ->andWhere('s.status = :pending')
            ->andWhere('s.expiresAt IS NOT NULL')
            ->andWhere('s.expiresAt < :now')
            ->setParameter('pending', ComicShare::STATUS_PENDING)
            ->setParameter('now', $now);

        if ($limit !== null) {
            $qb->setMaxResults($limit);
        }

        return $qb->getQuery()->getResult();
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
     * Shares that actually let this user read the comic behind them, aliased
     * `s` with the comic joined as `c`.
     *
     * Every read path resolves access through this, so an age gate cannot hold
     * on one endpoint and be missing on another: an accepted share on a comic
     * the owner has marked explicit grants nothing until that recipient has
     * declared their age on this very share.
     *
     * Note what it does *not* filter on: a comic the recipient removed from
     * their own collection is still readable, because hiding is not giving up.
     */
    private function readableQueryBuilder(User $user): \Doctrine\ORM\QueryBuilder
    {
        return $this->recipientQueryBuilder($user)
            ->innerJoin('s.comic', 'c')
            ->andWhere('s.status = :accepted')
            ->andWhere('s.unavailableAt IS NULL')
            ->andWhere('c.sharingRestrictedAt IS NULL')
            ->andWhere('c.quarantinedAt IS NULL')
            ->andWhere('c.explicitContent = false OR s.adultConfirmedAt IS NOT NULL')
            ->setParameter('accepted', ComicShare::STATUS_ACCEPTED);
    }

    /**
     * Whether this comic was ever put in front of this user, on any terms.
     *
     * Deliberately indifferent to status, expiry and tombstones, because it
     * answers a different question from {@see findAccessFor()}: not "may they
     * read it" but "do they already know it exists". Somebody sitting on a
     * declined invitation, a revoked share or one they have not aged into can
     * name the comic perfectly well, and is owed a straight refusal rather than
     * the "no such comic" that protects a stranger.
     */
    public function hasAnyShareFor(User $user, Comic $comic): bool
    {
        return (int) $this->recipientQueryBuilder($user)
            ->select('COUNT(s.id)')
            ->andWhere('s.comic = :comic')
            ->setParameter('comic', $comic)
            ->getQuery()
            ->getSingleScalarResult() > 0;
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

    /**
     * One page of share grants for the administrative table.
     *
     * A grant, not a code: a share made by emailed invitation has no code
     * behind it, and those are exactly the ones the sharing-codes table cannot
     * see. Somebody acting on a report needs the relationship itself.
     *
     * @param array{status?: string|null, comicId?: int|null, ownerId?: int|null, explicitOnly?: bool} $filters
     *
     * @return PaginatedResult<ComicShare>
     */
    public function findAdminPage(PaginationRequest $request, array $filters = []): PaginatedResult
    {
        $qb = $this->createQueryBuilder('s')
            ->leftJoin('s.comic', 'c')
            ->leftJoin('s.owner', 'o')
            ->leftJoin('s.recipientUser', 'r');

        if (in_array($filters['status'] ?? null, self::ADMIN_STATUSES, true)) {
            $qb->andWhere('s.status = :status')->setParameter('status', $filters['status']);
        }

        if (($filters['comicId'] ?? null) !== null) {
            $qb->andWhere('s.comic = :comicId')->setParameter('comicId', $filters['comicId']);
        }

        if (($filters['ownerId'] ?? null) !== null) {
            $qb->andWhere('s.owner = :ownerId')->setParameter('ownerId', $filters['ownerId']);
        }

        // The filter a report is usually about: what adult material is moving
        // between accounts on this instance.
        if (($filters['explicitOnly'] ?? false) === true) {
            $qb->andWhere('c.explicitContent = true');
        }

        // The comic, whoever handed it over, and whoever holds it. The stored
        // recipient address is included because a share to somebody with no
        // account yet is only identifiable by it.
        if ($pattern = $request->searchPattern()) {
            $qb->andWhere($qb->expr()->orX(
                'LOWER(c.title) LIKE :search',
                'LOWER(o.name) LIKE :search',
                'LOWER(o.email) LIKE :search',
                'LOWER(r.name) LIKE :search',
                'LOWER(r.email) LIKE :search',
                'LOWER(s.recipientEmail) LIKE :search',
            ))->setParameter('search', $pattern);
        }

        $total = (int) (clone $qb)->select('COUNT(s.id)')->getQuery()->getSingleScalarResult();

        $shares = $qb
            ->orderBy(self::ADMIN_SORT_FIELDS[$request->sortField], $request->direction)
            ->addOrderBy('s.id', 'DESC')
            ->setFirstResult($request->offset())
            ->setMaxResults($request->limit)
            ->getQuery()
            ->getResult();

        return PaginatedResult::fromRequest($shares, $total, $request);
    }
}
