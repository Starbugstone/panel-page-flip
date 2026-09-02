<?php

namespace App\Tests\Functional\Controller;

use App\Entity\Comic;
use App\Tests\Factory\ComicFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Functional\AbstractApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

final class ComicTitleBatchRenameTest extends AbstractApiTestCase
{
    public function testAComicTitleRenameRequestIsBounded(): void
    {
        $this->createAndLoginUser();
        $updates = array_fill(0, 5001, [
            'id' => 1,
            'currentTitle' => 'DragonBall 1',
            'title' => 'DragonBall 0001',
        ]);

        $payload = $this->patchJson('/api/comics/titles', ['updates' => $updates]);

        self::assertResponseStatusCodeSame(400);
        self::assertSame('A valid title updates array is required', $payload['message']);
    }

    public function testAComicFolderCanRenameMoreThanTheGenericBatchLimitAtomically(): void
    {
        $owner = $this->createAndLoginUser();
        $comics = ComicFactory::new()->ownedBy($owner)->many(201)->create();
        $updates = [];
        foreach ($comics as $index => $comic) {
            $updates[] = [
                'id' => $comic->getId(),
                'currentTitle' => $comic->getTitle(),
                'title' => sprintf('DragonBall %03d', $index + 1),
            ];
        }

        $payload = $this->patchJson('/api/comics/titles', ['updates' => $updates]);

        self::assertResponseIsSuccessful();
        self::assertCount(201, $payload['updatedComicIds']);
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();
        self::assertSame('DragonBall 001', $entityManager->find(Comic::class, $comics[0]->getId())->getTitle());
        self::assertSame('DragonBall 201', $entityManager->find(Comic::class, $comics[200]->getId())->getTitle());
    }

    public function testAStaleTitleRejectsTheWholeRename(): void
    {
        $owner = $this->createAndLoginUser();
        $first = ComicFactory::new()->ownedBy($owner)->create(['title' => 'DragonBall 1']);
        $second = ComicFactory::new()->ownedBy($owner)->create(['title' => 'DragonBall 10']);

        $payload = $this->patchJson('/api/comics/titles', ['updates' => [
            ['id' => $first->getId(), 'currentTitle' => 'DragonBall 1', 'title' => 'DragonBall 01'],
            ['id' => $second->getId(), 'currentTitle' => 'An old title', 'title' => 'DragonBall 10'],
        ]]);

        self::assertResponseStatusCodeSame(409);
        self::assertSame('One or more comic titles changed. Preview the rename again.', $payload['message']);
        self::assertSame('DragonBall 1', $first->getTitle());
        self::assertSame('DragonBall 10', $second->getTitle());
    }

    public function testAnotherOwnersComicRejectsTheWholeRename(): void
    {
        $owner = $this->createAndLoginUser();
        $owned = ComicFactory::new()->ownedBy($owner)->create(['title' => 'Owned 1']);
        $other = ComicFactory::new()->ownedBy(UserFactory::createOne())->create(['title' => 'Other 1']);

        $this->patchJson('/api/comics/titles', ['updates' => [
            ['id' => $owned->getId(), 'currentTitle' => 'Owned 1', 'title' => 'Owned 01'],
            ['id' => $other->getId(), 'currentTitle' => 'Other 1', 'title' => 'Other 01'],
        ]]);

        self::assertResponseStatusCodeSame(404);
        self::assertSame('Owned 1', $owned->getTitle());
        self::assertSame('Other 1', $other->getTitle());
    }
}
