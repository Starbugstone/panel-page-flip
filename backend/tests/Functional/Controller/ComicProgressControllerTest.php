<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Entity\ComicReadingProgress;
use App\Tests\Factory\ComicFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Functional\AbstractApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

final class ComicProgressControllerTest extends AbstractApiTestCase
{
    public function testRejectsAnInvalidPageOrRevision(): void
    {
        $owner = $this->createAndLoginUser();
        $comic = ComicFactory::new()->ownedBy($owner)->create(['pageCount' => 20]);

        $payload = $this->postJson('/api/comics/'.$comic->getId().'/progress', ['currentPage' => 0]);
        self::assertResponseStatusCodeSame(400);
        self::assertSame('Valid currentPage is required', $payload['message']);

        $payload = $this->postJson('/api/comics/'.$comic->getId().'/progress', [
            'currentPage' => 2,
            'revision' => 0,
        ]);
        self::assertResponseStatusCodeSame(400);
        self::assertSame('Invalid revision', $payload['message']);
    }

    public function testReachingTheLastPageCompletesTheComic(): void
    {
        $owner = $this->createAndLoginUser();
        $comic = ComicFactory::new()->ownedBy($owner)->create(['pageCount' => 20]);

        $payload = $this->postJson('/api/comics/'.$comic->getId().'/progress', [
            'currentPage' => 20,
            'revision' => 1,
        ]);

        self::assertResponseIsSuccessful();
        self::assertTrue($payload['progress']['completed']);
        self::assertSame(1, $payload['progress']['revision']);
    }

    public function testResetRemovesOnlyTheSignedInUsersPosition(): void
    {
        $owner = $this->createAndLoginUser();
        $comic = ComicFactory::new()->ownedBy($owner)->create(['pageCount' => 20]);
        $this->postJson('/api/comics/'.$comic->getId().'/progress', ['currentPage' => 5]);
        self::assertResponseIsSuccessful();

        $this->postJson('/api/comics/'.$comic->getId().'/reading-progress/reset');

        self::assertResponseIsSuccessful();
        self::assertNull($this->entityManager()->getRepository(ComicReadingProgress::class)->findOneBy([
            'comic' => $comic,
            'user' => $owner,
        ]));
    }

    public function testAdminInspectionDoesNotCreateReadingHistoryForAnotherUser(): void
    {
        $owner = UserFactory::createOne();
        $comic = ComicFactory::new()->ownedBy($owner)->create(['pageCount' => 20]);
        $admin = $this->createAndLoginAdmin();

        $payload = $this->postJson('/api/comics/'.$comic->getId().'/progress', [
            'currentPage' => 4,
            'revision' => 2,
        ]);

        self::assertResponseIsSuccessful();
        self::assertSame('Admin read-only progress ignored', $payload['message']);
        self::assertSame(2, $payload['progress']['revision']);
        self::assertNull($this->entityManager()->getRepository(ComicReadingProgress::class)->findOneBy([
            'comic' => $comic,
            'user' => $admin,
        ]));
    }

    private function entityManager(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }
}
