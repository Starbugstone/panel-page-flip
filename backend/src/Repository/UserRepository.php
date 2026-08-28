<?php

namespace App\Repository;

use App\Entity\Tag;
use App\Entity\User;
use App\Service\Pagination\PaginatedResult;
use App\Service\Pagination\PaginationRequest;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\PasswordUpgraderInterface;

/**
 * @extends ServiceEntityRepository<User>
 *
 * @method User|null find($id, $lockMode = null, $lockVersion = null)
 * @method User|null findOneBy(array $criteria, array $orderBy = null)
 * @method User[]    findAll()
 * @method User[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class UserRepository extends ServiceEntityRepository implements PasswordUpgraderInterface
{
    /**
     * Sortable columns for the admin user table, as query alias => DQL field.
     * Anything not listed here is rejected before it reaches the query.
     */
    public const ADMIN_SORT_FIELDS = [
        'name' => 'u.name',
        'email' => 'u.email',
        'createdAt' => 'u.createdAt',
        'lastLoginAt' => 'u.lastLoginAt',
    ];

    public function __construct(ManagerRegistry $registry, private readonly ComicRepository $comics)
    {
        parent::__construct($registry, User::class);
    }

    /**
     * One page of the admin user list.
     *
     * @param bool|null $verified Restrict to verified or unverified accounts.
     * @return PaginatedResult<User>
     */
    public function findAdminPage(PaginationRequest $request, ?bool $verified = null): PaginatedResult
    {
        $qb = $this->createQueryBuilder('u');

        if ($verified !== null) {
            $qb->andWhere('u.isEmailVerified = :verified')->setParameter('verified', $verified);
        }

        if ($pattern = $request->searchPattern()) {
            // Grouped explicitly, like the sibling repositories. Doctrine does
            // parenthesise a string part containing OR (DDC-1237), so this is
            // not a precedence fix — it just does not rely on that.
            $qb->andWhere($qb->expr()->orX(
                'LOWER(u.name) LIKE :search',
                'LOWER(u.email) LIKE :search',
            ))->setParameter('search', $pattern);
        }

        $total = (int) (clone $qb)->select('COUNT(u.id)')
            ->getQuery()
            ->getSingleScalarResult();

        // id DESC breaks ties so a user never appears on two pages, or on none,
        // when several rows share a created-at timestamp.
        $users = $qb
            ->orderBy(self::ADMIN_SORT_FIELDS[$request->sortField], $request->direction)
            ->addOrderBy('u.id', 'DESC')
            ->setFirstResult($request->offset())
            ->setMaxResults($request->limit)
            ->getQuery()
            ->getResult();

        return PaginatedResult::fromRequest($users, $total, $request);
    }

    /** @return list<User> */
    public function searchForContentReport(string $query, int $limit = 10): array
    {
        $query = trim($query);
        if (mb_strlen($query) < 2) {
            return [];
        }

        $pattern = '%'.mb_strtolower(addcslashes($query, '%_')).'%';
        return $this->createQueryBuilder('u')
            ->andWhere('LOWER(u.name) LIKE :search OR LOWER(u.email) LIKE :search')
            ->setParameter('search', $pattern)
            ->orderBy('u.id', 'DESC')
            ->setMaxResults(max(1, min($limit, 20)))
            ->getQuery()
            ->getResult();
    }

    /**
     * Owned-comic, storage and created-tag totals for the given users, keyed by
     * user id.
     *
     * Grouped queries rather than counting each user's collections in PHP, which
     * would hydrate every comic and tag in the install to render one page.
     *
     * The comic half comes from ComicRepository so that the number an
     * administrator reads is the number upload admission enforces; this method
     * only joins the tag counts onto it.
     *
     * @param list<int> $userIds
     * @return array<int, array{comicCount: int, tagCount: int, storageUsedBytes: int, unmeasuredComicCount: int}>
     */
    public function getOwnedContentStats(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        $stats = [];
        foreach ($this->comics->getStorageStatsByOwner($userIds) as $userId => $comicStats) {
            $stats[$userId] = $comicStats + ['tagCount' => 0];
        }

        $tagRows = $this->getEntityManager()->createQueryBuilder()
            ->select('IDENTITY(t.creator) AS creatorId', 'COUNT(t.id) AS total')
            ->from(Tag::class, 't')
            ->where('t.creator IN (:userIds)')
            ->groupBy('t.creator')
            ->setParameter('userIds', $userIds)
            ->getQuery()
            ->getScalarResult();
        foreach ($tagRows as $row) {
            $stats[(int) $row['creatorId']]['tagCount'] = (int) $row['total'];
        }

        return $stats;
    }

    /**
     * Used to upgrade (rehash) the user's password automatically over time.
     */
    public function upgradePassword(PasswordAuthenticatedUserInterface $user, string $newHashedPassword): void
    {
        if (!$user instanceof User) {
            throw new UnsupportedUserException(sprintf('Instances of "%s" are not supported.', $user::class));
        }

        $user->setPassword($newHashedPassword);
        $this->getEntityManager()->persist($user);
        $this->getEntityManager()->flush();
    }

    public function countAdminsExcluding(User $excludedUser): int
    {
        return (int) $this->createQueryBuilder('u')
            ->select('COUNT(u.id)')
            ->where('u.id != :excludedId')
            ->andWhere('u.roles LIKE :adminRole')
            ->setParameter('excludedId', $excludedUser->getId())
            ->setParameter('adminRole', '%ROLE_ADMIN%')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Every administrator account, for the security-alert fallback recipient
     * list. Ordered so the recipients of one alert are the recipients of the
     * next, rather than whatever the database returned this time.
     *
     * @return list<User>
     */
    public function findAdmins(): array
    {
        return $this->createQueryBuilder('u')
            ->where('u.roles LIKE :adminRole')
            ->setParameter('adminRole', '%ROLE_ADMIN%')
            ->orderBy('u.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function lockAdministrators(): void
    {
        $this->createQueryBuilder('u')
            ->where('u.roles LIKE :adminRole')
            ->setParameter('adminRole', '%ROLE_ADMIN%')
            ->orderBy('u.id', 'ASC')
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->getResult();
    }
}
