<?php

namespace App\Repository;

use App\Entity\Comic;
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

    public function __construct(ManagerRegistry $registry)
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
            $qb->andWhere('LOWER(u.name) LIKE :search OR LOWER(u.email) LIKE :search')
                ->setParameter('search', $pattern);
        }

        $total = (int) (clone $qb)->select('COUNT(u.id)')
            ->resetDQLPart('orderBy')
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

    /**
     * Owned-comic and created-tag totals for the given users, keyed by user id.
     *
     * Two grouped queries rather than counting each user's collections in PHP,
     * which would hydrate every comic and tag in the install to render one page.
     *
     * @param list<int> $userIds
     * @return array<int, array{comicCount: int, tagCount: int}>
     */
    public function countOwnedContent(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        $counts = array_fill_keys($userIds, ['comicCount' => 0, 'tagCount' => 0]);

        $comicRows = $this->getEntityManager()->createQueryBuilder()
            ->select('IDENTITY(c.owner) AS ownerId', 'COUNT(c.id) AS total')
            ->from(Comic::class, 'c')
            ->where('c.owner IN (:userIds)')
            ->groupBy('c.owner')
            ->setParameter('userIds', $userIds)
            ->getQuery()
            ->getScalarResult();
        foreach ($comicRows as $row) {
            $counts[(int) $row['ownerId']]['comicCount'] = (int) $row['total'];
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
            $counts[(int) $row['creatorId']]['tagCount'] = (int) $row['total'];
        }

        return $counts;
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
