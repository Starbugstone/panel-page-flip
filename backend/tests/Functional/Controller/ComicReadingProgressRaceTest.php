<?php

namespace App\Tests\Functional\Controller;

use App\Entity\Comic;
use App\Entity\ComicReadingProgress;
use App\Entity\User;
use App\Tests\Factory\ComicFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Functional\AbstractApiTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PrePersistEventArgs;
use Doctrine\ORM\Events;

/**
 * Two reading-progress saves arriving at once.
 *
 * Saving a position is "find the row, create it if it is missing" — a read
 * followed by a write. Two saves that interleave inside that gap both find
 * nothing and both insert, and without a unique index nothing stops them: the
 * reader ends up with two rows and every later lookup returns whichever the
 * database offers first, so their position flips between two pages.
 *
 * The gap cannot be closed by checking harder, so the database refuses the
 * duplicate and the request that lost recovers. Both halves are asserted here:
 * one row survives, and the save that lost the race is still applied rather
 * than being reported to the reader as a failure.
 *
 * The interleaving is made deterministic with a prePersist listener. It fires
 * during persist(), before flush() opens its transaction, so the row it writes
 * survives the rollback of the failed insert — which is exactly the ordering a
 * real competing request produces.
 */
final class ComicReadingProgressRaceTest extends AbstractApiTestCase
{
    public function testASaveThatLosesTheRaceIsAppliedToTheRowThatWon(): void
    {
        [$user, $comic] = $this->createReadableComic();

        $this->interleaveCompetingInsert($user, $comic, currentPage: 2, revision: 1);

        $payload = $this->postJson(
            sprintf('/api/comics/%d/progress', $comic->getId()),
            ['currentPage' => 7, 'revision' => 3]
        );

        // Not a 500, and not a silent no-op: the reader turned to page 7 and
        // page 7 is what was stored.
        self::assertResponseIsSuccessful();
        self::assertSame(7, $payload['progress']['currentPage']);
        self::assertSame(3, $payload['progress']['revision']);

        self::assertSame(1, $this->countProgressRows($user, $comic));
        self::assertSame(7, $this->storedPage($user, $comic));
    }

    /**
     * The same race without a revision. Older clients do not send one, and the
     * recovery must not depend on it.
     */
    public function testARevisionlessSaveAlsoSurvivesTheRace(): void
    {
        [$user, $comic] = $this->createReadableComic();

        $this->interleaveCompetingInsert($user, $comic, currentPage: 2, revision: 0);

        $payload = $this->postJson(
            sprintf('/api/comics/%d/progress', $comic->getId()),
            ['currentPage' => 5]
        );

        self::assertResponseIsSuccessful();
        self::assertSame(5, $payload['progress']['currentPage']);
        self::assertSame(1, $this->countProgressRows($user, $comic));
        self::assertSame(5, $this->storedPage($user, $comic));
    }

    /**
     * The winner of the race keeps its position when the loser is the stale one.
     *
     * The conditional update is keyed on the revision, so a save that was
     * superseded while it was in flight must not roll the reader backwards even
     * though it is the request doing the recovering.
     */
    public function testARaceLoserThatIsAlreadyStaleDoesNotRewindTheReader(): void
    {
        [$user, $comic] = $this->createReadableComic();

        // The competing save is newer than the one being made.
        $this->interleaveCompetingInsert($user, $comic, currentPage: 40, revision: 9);

        $payload = $this->postJson(
            sprintf('/api/comics/%d/progress', $comic->getId()),
            ['currentPage' => 7, 'revision' => 3]
        );

        self::assertResponseIsSuccessful();
        self::assertSame(1, $this->countProgressRows($user, $comic));
        self::assertSame(40, $this->storedPage($user, $comic));
        self::assertSame(40, $payload['progress']['currentPage']);
    }

    /**
     * The database is what enforces this, not the code above it. A second row
     * for the same reader and comic must be impossible even when the ORM is
     * bypassed entirely.
     */
    public function testTheDatabaseRefusesASecondRowForTheSameReaderAndComic(): void
    {
        [$user, $comic] = $this->createReadableComic();

        $this->insertProgressRow($user, $comic, 2, 1);

        $this->expectException(\Doctrine\DBAL\Exception\UniqueConstraintViolationException::class);
        $this->insertProgressRow($user, $comic, 3, 2);
    }

    /**
     * Write a competing row the instant the request under test decides to
     * create its own, so the two collide the way concurrent requests do.
     */
    private function interleaveCompetingInsert(User $user, Comic $comic, int $currentPage, int $revision): void
    {
        $entityManager = $this->entityManager();
        $listener = new class ($this, $user, $comic, $currentPage, $revision) {
            private bool $fired = false;

            public function __construct(
                private readonly ComicReadingProgressRaceTest $test,
                private readonly User $user,
                private readonly Comic $comic,
                private readonly int $currentPage,
                private readonly int $revision,
            ) {
            }

            public function prePersist(PrePersistEventArgs $args): void
            {
                if ($this->fired || !$args->getObject() instanceof ComicReadingProgress) {
                    return;
                }

                // Once only: the recovery re-runs the save, and a listener that
                // kept firing would describe a livelock rather than a race.
                $this->fired = true;
                $this->test->insertProgressRow($this->user, $this->comic, $this->currentPage, $this->revision);
            }
        };

        $entityManager->getEventManager()->addEventListener([Events::prePersist], $listener);
    }

    /** Insert straight through DBAL, so the ORM's unit of work knows nothing about it. */
    public function insertProgressRow(User $user, Comic $comic, int $currentPage, int $revision): void
    {
        $this->entityManager()->getConnection()->insert('comic_reading_progress', [
            'user_id' => $user->getId(),
            'comic_id' => $comic->getId(),
            'current_page' => $currentPage,
            'last_read_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            'completed' => 0,
            'revision' => $revision,
        ]);
    }

    private function countProgressRows(User $user, Comic $comic): int
    {
        return (int) $this->entityManager()->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM comic_reading_progress WHERE user_id = ? AND comic_id = ?',
            [$user->getId(), $comic->getId()]
        );
    }

    private function storedPage(User $user, Comic $comic): int
    {
        return (int) $this->entityManager()->getConnection()->fetchOne(
            'SELECT current_page FROM comic_reading_progress WHERE user_id = ? AND comic_id = ?',
            [$user->getId(), $comic->getId()]
        );
    }

    /**
     * Resolved fresh each time: the recovery path replaces the entity manager,
     * and a handle taken before the request would be the closed one.
     */
    private function entityManager(): EntityManagerInterface
    {
        return self::getContainer()->get('doctrine')->getManager();
    }

    /**
     * @return array{0: User, 1: Comic}
     */
    private function createReadableComic(): array
    {
        $user = UserFactory::createOne()->object();
        $comic = ComicFactory::createOne(['owner' => $user, 'pageCount' => 100])->object();
        $this->loginAs($user);

        return [$user, $comic];
    }
}
