<?php

declare(strict_types=1);

namespace App\Tests\Functional\Repository;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Tests\Factory\ComicFactory;
use App\Tests\Factory\TagFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Functional\AbstractApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The grouped totals behind the admin user table.
 *
 * Storage is why this exists: the admin list has to report the same bytes quota
 * enforcement counts, and has to say so plainly when some of those sizes were
 * never recorded.
 */
final class UserContentStatsTest extends AbstractApiTestCase
{
    public function testAnAccountWithNoComicsUsesNoStorage(): void
    {
        $user = UserFactory::createOne()->object();

        $stats = $this->statsFor($user);

        self::assertSame(0, $stats['comicCount']);
        self::assertSame(0, $stats['storageUsedBytes']);
        self::assertSame(0, $stats['unmeasuredComicCount']);
    }

    public function testStorageIsTheExactSumOfTheOwnedComicSizes(): void
    {
        $user = UserFactory::createOne()->object();
        ComicFactory::createOne(['owner' => $user, 'fileSize' => 1_000]);
        ComicFactory::createOne(['owner' => $user, 'fileSize' => 2_500]);

        $stats = $this->statsFor($user);

        self::assertSame(2, $stats['comicCount']);
        self::assertSame(3_500, $stats['storageUsedBytes']);
    }

    public function testOneOwnersBytesNeverLeakIntoAnothers(): void
    {
        $alice = UserFactory::createOne()->object();
        $bob = UserFactory::createOne()->object();
        ComicFactory::createOne(['owner' => $alice, 'fileSize' => 700]);
        ComicFactory::createOne(['owner' => $bob, 'fileSize' => 90]);
        ComicFactory::createOne(['owner' => $bob, 'fileSize' => 9]);

        $stats = $this->repository()->getOwnedContentStats([$alice->getId(), $bob->getId()]);

        self::assertSame(700, $stats[$alice->getId()]['storageUsedBytes']);
        self::assertSame(99, $stats[$bob->getId()]['storageUsedBytes']);
    }

    /**
     * The count and the byte total come out of one grouped query, so a comic
     * cannot be counted by one and missed by the other.
     */
    public function testCountAndStorageDescribeTheSameComics(): void
    {
        $user = UserFactory::createOne()->object();
        ComicFactory::createMany(3, ['owner' => $user, 'fileSize' => 10]);
        TagFactory::createOne(['creator' => $user]);

        $stats = $this->statsFor($user);

        self::assertSame(3, $stats['comicCount']);
        self::assertSame(30, $stats['storageUsedBytes']);
        self::assertSame(1, $stats['tagCount']);
    }

    /**
     * Sharing hands over access to the owner's file and copies nothing, so a
     * recipient's quota is untouched. Ownership is what the sum keys on.
     */
    public function testAComicSharedWithSomebodyElseStaysOnTheOwnersTotal(): void
    {
        $owner = UserFactory::createOne()->object();
        $recipient = UserFactory::createOne()->object();
        ComicFactory::createOne(['owner' => $owner, 'fileSize' => 4_096]);

        $stats = $this->repository()->getOwnedContentStats([$owner->getId(), $recipient->getId()]);

        self::assertSame(4_096, $stats[$owner->getId()]['storageUsedBytes']);
        self::assertSame(0, $stats[$recipient->getId()]['storageUsedBytes']);
        self::assertSame(0, $stats[$recipient->getId()]['comicCount']);
    }

    /**
     * SUM() skips NULLs without complaint. Counting them separately is what
     * stops an understated total from being presented as exact.
     */
    public function testComicsWithNoRecordedSizeAreReportedAsUnmeasured(): void
    {
        $user = UserFactory::createOne()->object();
        ComicFactory::createOne(['owner' => $user, 'fileSize' => 500]);
        ComicFactory::createOne(['owner' => $user, 'fileSize' => null]);
        ComicFactory::createOne(['owner' => $user, 'fileSize' => null]);

        $stats = $this->statsFor($user);

        self::assertSame(3, $stats['comicCount']);
        self::assertSame(500, $stats['storageUsedBytes']);
        self::assertSame(2, $stats['unmeasuredComicCount']);
    }

    /** A library past 2 GiB is ordinary here, and must not arrive as a string. */
    public function testLargeTotalsStayIntegers(): void
    {
        $user = UserFactory::createOne()->object();
        ComicFactory::createOne(['owner' => $user, 'fileSize' => 3_000_000_000]);
        ComicFactory::createOne(['owner' => $user, 'fileSize' => 3_000_000_000]);

        $stats = $this->statsFor($user);

        self::assertSame(6_000_000_000, $stats['storageUsedBytes']);
    }

    public function testAskingAboutNobodyReturnsNothing(): void
    {
        self::assertSame([], $this->repository()->getOwnedContentStats([]));
    }

    /** @return array{comicCount: int, tagCount: int, storageUsedBytes: int, unmeasuredComicCount: int} */
    private function statsFor(User $user): array
    {
        return $this->repository()->getOwnedContentStats([$user->getId()])[$user->getId()];
    }

    private function repository(): UserRepository
    {
        /** @var UserRepository $repository */
        $repository = static::getContainer()->get(EntityManagerInterface::class)->getRepository(User::class);

        return $repository;
    }
}
