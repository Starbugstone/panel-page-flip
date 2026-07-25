<?php

namespace App\Tests\Functional\Controller;

use App\Entity\Comic;
use App\Entity\Tag;
use App\Tests\Factory\ComicFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Functional\AbstractApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

final class ComicBulkControllerTest extends AbstractApiTestCase
{
    public function testSingleAndMultipleEditsUseTheSameBatchContract(): void
    {
        $owner = $this->createAndLoginUser();
        $first = ComicFactory::new()->ownedBy($owner)->create(['title' => 'First'])->object();
        $second = ComicFactory::new()->ownedBy($owner)->create(['title' => 'Second'])->object();

        $payload = $this->patchJson('/api/comics', [
            'updates' => [
                ['id' => $first->getId(), 'changes' => ['title' => 'First updated', 'author' => 'Shared Author']],
                ['id' => $second->getId(), 'changes' => ['title' => 'Second updated', 'author' => 'Shared Author']],
            ],
        ]);

        self::assertResponseIsSuccessful();
        self::assertCount(2, $payload['updatedComicIds']);
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $first = $entityManager->find(Comic::class, $first->getId());
        $second = $entityManager->find(Comic::class, $second->getId());
        self::assertSame('First updated', $first->getTitle());
        self::assertSame('Second updated', $second->getTitle());
        self::assertSame('Shared Author', $first->getAuthor());
        self::assertSame('Shared Author', $second->getAuthor());
    }

    public function testOwnerCanAddTagToSelectedComics(): void
    {
        $owner = $this->createAndLoginUser();
        $first = ComicFactory::new()->ownedBy($owner)->create()->object();
        $second = ComicFactory::new()->ownedBy($owner)->create()->object();

        $payload = $this->patchJson('/api/comics', [
            'updates' => [
                ['id' => $first->getId(), 'changes' => ['addTags' => ['Weekend']]],
                ['id' => $second->getId(), 'changes' => ['addTags' => ['Weekend']]],
            ],
        ]);

        self::assertResponseIsSuccessful();
        self::assertSame([$first->getId(), $second->getId()], $payload['updatedComicIds']);
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();
        $tag = $entityManager->getRepository(Tag::class)->findOneBy(['name' => 'Weekend', 'creator' => $owner]);
        self::assertNotNull($tag);
        self::assertCount(2, $tag->getComics());
    }

    public function testBulkTagRejectsAnotherUsersComicWithoutChangingAnyComic(): void
    {
        $owner = $this->createAndLoginUser();
        $ownedComic = ComicFactory::new()->ownedBy($owner)->create()->object();
        $otherComic = ComicFactory::createOne()->object();

        $this->patchJson('/api/comics', [
            'updates' => [
                ['id' => $ownedComic->getId(), 'changes' => ['addTags' => ['Private']]],
                ['id' => $otherComic->getId(), 'changes' => ['addTags' => ['Private']]],
            ],
        ]);

        self::assertResponseStatusCodeSame(404);
        self::assertSame(0, static::getContainer()->get(EntityManagerInterface::class)
            ->getRepository(Tag::class)->count(['name' => 'Private']));
    }

    public function testOwnerCanBulkDeleteSelectedComics(): void
    {
        $owner = $this->createAndLoginUser();
        $first = ComicFactory::new()->ownedBy($owner)->create()->object();
        $second = ComicFactory::new()->ownedBy($owner)->create()->object();
        $kept = ComicFactory::new()->ownedBy($owner)->create()->object();

        $payload = $this->deleteJson('/api/comics', [
            'comicIds' => [$first->getId(), $second->getId()],
        ]);

        self::assertResponseIsSuccessful();
        self::assertSame([$first->getId(), $second->getId()], $payload['deletedComicIds']);
        $repository = static::getContainer()->get(EntityManagerInterface::class)->getRepository(Comic::class);
        self::assertNull($repository->find($first->getId()));
        self::assertNull($repository->find($second->getId()));
        self::assertNotNull($repository->find($kept->getId()));
    }

    public function testBulkDeleteRejectsMixedOwnershipWithoutDeletingAnything(): void
    {
        $owner = $this->createAndLoginUser();
        $ownedComic = ComicFactory::new()->ownedBy($owner)->create()->object();
        $otherComic = ComicFactory::createOne()->object();

        $this->deleteJson('/api/comics', [
            'comicIds' => [$ownedComic->getId(), $otherComic->getId()],
        ]);

        self::assertResponseStatusCodeSame(404);
        self::assertSame(2, ComicFactory::repository()->count());
    }

    public function testBatchUpdateRejectsInvalidMetadataWithoutPartialChanges(): void
    {
        $owner = $this->createAndLoginUser();
        $first = ComicFactory::new()->ownedBy($owner)->create(['title' => 'Unchanged first'])->object();
        $second = ComicFactory::new()->ownedBy($owner)->create(['title' => 'Unchanged second'])->object();

        $this->patchJson('/api/comics', [
            'updates' => [
                ['id' => $first->getId(), 'changes' => ['title' => 'Would have changed']],
                ['id' => $second->getId(), 'changes' => ['title' => str_repeat('x', 256)]],
            ],
        ]);

        self::assertResponseStatusCodeSame(400);
        self::assertSame('Unchanged first', $first->getTitle());
        self::assertSame('Unchanged second', $second->getTitle());
    }
}
