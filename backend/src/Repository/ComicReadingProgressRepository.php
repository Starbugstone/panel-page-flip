<?php

namespace App\Repository;

use App\Entity\Comic;
use App\Entity\ComicReadingProgress;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ComicReadingProgress>
 *
 * @method ComicReadingProgress|null find($id, $lockMode = null, $lockVersion = null)
 * @method ComicReadingProgress|null findOneBy(array $criteria, array $orderBy = null)
 * @method ComicReadingProgress[]    findAll()
 * @method ComicReadingProgress[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
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
}
