<?php

namespace App\Tests\Functional\Controller;

use App\Entity\Comic;
use App\Entity\ShareToken;
use App\Entity\Tag;
use App\Tests\Factory\ComicFactory;
use App\Tests\Factory\TagFactory;
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

    public function testBulkTagReusesAnExistingTagDespiteCaseAndWhitespace(): void
    {
        $owner = $this->createAndLoginUser();
        $existing = TagFactory::new()->createdBy($owner)->create(['name' => 'Sci Fi'])->object();
        $comic = ComicFactory::new()->ownedBy($owner)->create()->object();

        $this->patchJson('/api/comics', [
            'updates' => [
                ['id' => $comic->getId(), 'changes' => ['addTags' => ['  sci fi ']]],
            ],
        ]);

        self::assertResponseIsSuccessful();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();
        // One tag, still spelled the way its owner created it.
        self::assertSame(1, $entityManager->getRepository(Tag::class)->count(['creator' => $owner]));
        $tag = $entityManager->getRepository(Tag::class)->find($existing->getId());
        self::assertSame('Sci Fi', $tag->getName());
        self::assertCount(1, $tag->getComics());
    }

    public function testRepeatedBulkTagSubmissionDoesNotAttachDuplicates(): void
    {
        $owner = $this->createAndLoginUser();
        $comic = ComicFactory::new()->ownedBy($owner)->create()->object();
        $request = ['updates' => [['id' => $comic->getId(), 'changes' => ['addTags' => ['Weekend']]]]];

        $this->patchJson('/api/comics', $request);
        self::assertResponseIsSuccessful();
        $this->patchJson('/api/comics', $request);

        self::assertResponseIsSuccessful();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();
        self::assertSame(1, $entityManager->getRepository(Tag::class)->count(['name' => 'Weekend']));
        self::assertCount(1, $entityManager->find(Comic::class, $comic->getId())->getTags());
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
            'confirmOrphaned' => true,
        ]);

        self::assertResponseIsSuccessful();
        self::assertSame([$first->getId(), $second->getId()], $payload['deletedComicIds']);
        $repository = static::getContainer()->get(EntityManagerInterface::class)->getRepository(Comic::class);
        self::assertNull($repository->find($first->getId()));
        self::assertNull($repository->find($second->getId()));
        self::assertNotNull($repository->find($kept->getId()));
    }

    public function testBulkDeleteRequiresConfirmationForMissingComicFiles(): void
    {
        $owner = $this->createAndLoginUser();
        $orphanedComic = ComicFactory::new()->ownedBy($owner)->create([
            'title' => 'Missing archive',
            'filePath' => 'missing-archive.cbz',
        ])->object();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $shareToken = new ShareToken($orphanedComic, $owner, 'recipient@example.com');
        $entityManager->persist($shareToken);
        $entityManager->flush();
        $shareTokenId = $shareToken->getId();

        $warning = $this->deleteJson('/api/comics', [
            'comicIds' => [$orphanedComic->getId()],
        ]);

        self::assertResponseStatusCodeSame(409);
        self::assertSame('orphaned_comics_confirmation_required', $warning['code']);
        self::assertSame($orphanedComic->getId(), $warning['orphanedComics'][0]['id']);
        self::assertNotNull($entityManager->getRepository(Comic::class)->find($orphanedComic->getId()));
        self::assertNotNull($entityManager->getRepository(ShareToken::class)->find($shareTokenId));

        $deleted = $this->deleteJson('/api/comics', [
            'comicIds' => [$orphanedComic->getId()],
            'confirmOrphaned' => true,
        ]);

        self::assertResponseIsSuccessful();
        self::assertSame([$orphanedComic->getId()], $deleted['orphanedComicIds']);
        self::assertNull($entityManager->getRepository(Comic::class)->find($orphanedComic->getId()));
        self::assertNull($entityManager->getRepository(ShareToken::class)->find($shareTokenId));
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
