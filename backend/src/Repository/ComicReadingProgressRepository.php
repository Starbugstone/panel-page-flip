<?php

namespace App\Repository;

use App\Entity\Comic;
use App\Entity\ComicReadingProgress;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ComicReadingProgress>
 */
class ComicReadingProgressRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ComicReadingProgress::class);
    }

    public function findByUserAndComic(User $user, Comic $comic): ?ComicReadingProgress
    {
        return $this->findOneBy(['user' => $user, 'comic' => $comic]);
    }

    /**
     * Load one user's progress across many comics in a single query, keyed by
     * comic id. Serializing a library one comic at a time would otherwise cost
     * a query per comic.
     *
     * @param list<Comic> $comics
     * @return array<int, ComicReadingProgress>
     */
    public function findByUserIndexedByComic(User $user, array $comics): array
    {
        if ($comics === []) {
            return [];
        }

        $progresses = $this->createQueryBuilder('p')
            ->andWhere('p.user = :user')
            ->andWhere('p.comic IN (:comics)')
            ->setParameter('user', $user)
            ->setParameter('comics', $comics)
            ->getQuery()
            ->getResult();

        $indexed = [];
        foreach ($progresses as $progress) {
            $indexed[$progress->getComic()->getId()] = $progress;
        }

        return $indexed;
    }
}
