<?php

namespace App\Repository;

use App\Entity\Comic;
use App\Entity\Tag;
use App\Entity\User;
use App\Service\Pagination\ColumnFilter;
use App\Service\Pagination\LikePattern;
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
        'comicCount' => 'comicCountSort',
        'storage' => 'storageSort',
        'role' => 'u.roles',
        'verified' => 'u.isEmailVerified',
    ];

    /** The Verified? column's two values, as its cells spell them. */
    private const VERIFICATION_LABELS = ['verified' => 'Verified', 'pending' => 'Pending'];

    public function __construct(ManagerRegistry $registry, private readonly ComicRepository $comics)
    {
        parent::__construct($registry, User::class);
    }

    /**
     * One page of the admin user list.
     *
     * @param bool|null $verified Restrict to verified or unverified accounts.
     * @param array{identity?: string|null, role?: string|null, verified?: string|null,
     *               createdAt?: string|null, lastLoginAt?: string|null, comicCount?: string|null,
     *               storage?: string|null} $filters
     * @return PaginatedResult<User>
     */
    public function findAdminPage(PaginationRequest $request, ?bool $verified = null, array $filters = []): PaginatedResult
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

        if ($pattern = ColumnFilter::pattern($filters['identity'] ?? null)) {
            $qb->andWhere($qb->expr()->orX(
                'LOWER(u.name) LIKE :filterIdentity',
                'LOWER(u.email) LIKE :filterIdentity',
            ))->setParameter('filterIdentity', $pattern);
        }

        if ($pattern = ColumnFilter::pattern($filters['role'] ?? null)) {
            $qb->andWhere('LOWER(u.roles) LIKE :filterRole')->setParameter('filterRole', $pattern);
        }

        $filterVerified = ColumnFilter::matchLabel($qb, $filters['verified'] ?? null, self::VERIFICATION_LABELS);
        if ($filterVerified !== null) {
            $qb->andWhere('u.isEmailVerified = :filterVerified')
                ->setParameter('filterVerified', $filterVerified === 'verified');
        }

        ColumnFilter::applyDay($qb, 'u.createdAt', 'filterCreatedAt', $filters['createdAt'] ?? null);
        if (mb_strtolower(ColumnFilter::text($filters['lastLoginAt'] ?? null)) === 'never') {
            $qb->andWhere('u.lastLoginAt IS NULL');
        } else {
            ColumnFilter::applyDay($qb, 'u.lastLoginAt', 'filterLastLoginAt', $filters['lastLoginAt'] ?? null);
        }

        $comicCount = ColumnFilter::text($filters['comicCount'] ?? null);
        if (ctype_digit($comicCount)) {
            $qb->andWhere(sprintf(
                '(SELECT COUNT(filterComic.id) FROM %s filterComic WHERE filterComic.owner = u) = :filterComicCount',
                Comic::class,
            ))->setParameter('filterComicCount', (int) $comicCount);
        }

        if ($storageRange = $this->storageRange($filters['storage'] ?? null)) {
            [$minimum, $maximum] = $storageRange;
            $storageExpression = static fn (string $alias): string => sprintf(
                '(SELECT SUM(%1$s.fileSize) FROM %2$s %1$s WHERE %1$s.owner = u)',
                $alias,
                Comic::class,
            );
            if ($minimum === 0) {
                $qb->andWhere(sprintf(
                    '(%s IS NULL OR %s <= :filterStorageMax)',
                    $storageExpression('emptyStorageComic'),
                    $storageExpression('maximumStorageComic'),
                ))
                    ->setParameter('filterStorageMax', $maximum);
            } else {
                $qb->andWhere(sprintf('%s >= :filterStorageMin', $storageExpression('minimumStorageComic')))
                    ->andWhere(sprintf('%s <= :filterStorageMax', $storageExpression('maximumStorageComic')))
                    ->setParameter('filterStorageMin', $minimum)
                    ->setParameter('filterStorageMax', $maximum);
            }
        }

        $total = (int) (clone $qb)->select('COUNT(u.id)')
            ->getQuery()
            ->getSingleScalarResult();

        // id DESC breaks ties so a user never appears on two pages, or on none,
        // when several rows share a created-at timestamp.
        if ($request->sortField === 'comicCount') {
            $qb->addSelect(sprintf(
                '(SELECT COUNT(sortComic.id) FROM %s sortComic WHERE sortComic.owner = u) AS HIDDEN comicCountSort',
                Comic::class,
            ));
        }
        if ($request->sortField === 'storage') {
            $qb->addSelect(sprintf(
                '(SELECT SUM(sortStorageComic.fileSize) FROM %s sortStorageComic WHERE sortStorageComic.owner = u) AS HIDDEN storageSort',
                Comic::class,
            ));
        }

        $users = $qb
            ->orderBy(self::ADMIN_SORT_FIELDS[$request->sortField], $request->direction)
            ->addOrderBy('u.id', 'DESC')
            ->setFirstResult($request->offset())
            ->setMaxResults($request->limit)
            ->getQuery()
            ->getResult();

        return PaginatedResult::fromRequest($users, $total, $request);
    }

    /** @return array{int, int}|null Byte range that rounds to the displayed value. */
    private function storageRange(mixed $value): ?array
    {
        $value = ColumnFilter::text($value);
        if (!preg_match('/^(\d+(?:\.\d+)?)\s*(B|KIB|MIB|GIB|TIB)?$/i', $value, $matches)) return null;

        $units = ['B' => [1, 0], 'KIB' => [1024, 1], 'MIB' => [1024 ** 2, 1], 'GIB' => [1024 ** 3, 2], 'TIB' => [1024 ** 4, 2]];
        [$scale, $decimals] = $units[strtoupper($matches[2] ?? 'B')];
        $number = (float) $matches[1];
        $halfStep = 0.5 * 10 ** (-$decimals);

        return [
            max(0, (int) ceil(($number - $halfStep) * $scale)),
            max(0, (int) floor(($number + $halfStep) * $scale)),
        ];
    }

    /** @return list<User> */
    public function searchForContentReport(string $query, int $limit = 10): array
    {
        $query = trim($query);
        if (mb_strlen($query) < LikePattern::MIN_TERM_LENGTH) {
            return [];
        }

        $pattern = LikePattern::contains($query);
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
