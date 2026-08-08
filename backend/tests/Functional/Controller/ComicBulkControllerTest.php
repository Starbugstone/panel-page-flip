<?php

namespace App\Tests\Functional\Controller;

use App\Entity\Comic;
use App\Entity\ComicShare;
use App\Entity\Tag;
use App\Service\SecurityAuditLogger;
use App\Tests\Factory\ComicFactory;
use App\Tests\Factory\TagFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Functional\AbstractApiTestCase;
use App\Tests\Functional\SecurityLogAssertions;
use Doctrine\ORM\EntityManagerInterface;

final class ComicBulkControllerTest extends AbstractApiTestCase
{
    use SecurityLogAssertions;

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

    /**
     * A sweep large enough to look like a compromised account raises an alarm
     * as well as leaving a trail, and the two are separate records on separate
     * channels. Sharing one event name would make a single deletion appear
     * twice to anything counting occurrences — which is exactly what the
     * thresholds behind the alerts do.
     */
    public function testAnUnusuallyLargeBulkDeleteAlarmsSeparatelyFromItsAuditTrail(): void
    {
        $owner = $this->createAndLoginUser();
        $comics = ComicFactory::new()->ownedBy($owner)->many(25)->create();
        $comicIds = array_map(static fn ($comic): int => $comic->object()->getId(), $comics);

        $this->deleteJson('/api/comics', [
            'comicIds' => $comicIds,
            'confirmOrphaned' => true,
        ]);
        self::assertResponseIsSuccessful();

        $audited = $this->assertLoggedAuditEvent(SecurityAuditLogger::COMICS_BULK_DELETED);
        self::assertSame(25, $audited->context['count']);
        // The trail is a record of what happened, never an alarm.
        $this->assertNoSecurityEvent(SecurityAuditLogger::COMICS_BULK_DELETED);

        $alarm = $this->assertLoggedSecurityEvent(SecurityAuditLogger::COMIC_BULK_DELETE_UNUSUAL);
        self::assertSame($owner->getId(), $alarm->context['actor_user_id']);
        self::assertCount(1, $this->alertsAbout(SecurityAuditLogger::COMIC_BULK_DELETE_UNUSUAL));
    }

    public function testAnOrdinaryBulkDeleteIsAuditedWithoutAlarmingAnybody(): void
    {
        $owner = $this->createAndLoginUser();
        $comics = ComicFactory::new()->ownedBy($owner)->many(2)->create();

        $this->deleteJson('/api/comics', [
            'comicIds' => array_map(static fn ($comic): int => $comic->object()->getId(), $comics),
            'confirmOrphaned' => true,
        ]);
        self::assertResponseIsSuccessful();

        $this->assertLoggedAuditEvent(SecurityAuditLogger::COMICS_BULK_DELETED);
        $this->assertNoSecurityEvent(SecurityAuditLogger::COMIC_BULK_DELETE_UNUSUAL);
        self::assertSame([], $this->alertsAbout(SecurityAuditLogger::COMIC_BULK_DELETE_UNUSUAL));
    }

    public function testBulkDeleteRequiresConfirmationForMissingComicFiles(): void
    {
        $owner = $this->createAndLoginUser();
        $orphanedComic = ComicFactory::new()->ownedBy($owner)->create([
            'title' => 'Missing archive',
            'filePath' => 'missing-archive.cbz',
        ])->object();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $share = new ComicShare($orphanedComic, $owner, 'recipient@example.com');
        $entityManager->persist($share);
        $entityManager->flush();
        $shareId = $share->getId();

        $warning = $this->deleteJson('/api/comics', [
            'comicIds' => [$orphanedComic->getId()],
        ]);

        self::assertResponseStatusCodeSame(409);
        self::assertSame('orphaned_comics_confirmation_required', $warning['code']);
        self::assertSame($orphanedComic->getId(), $warning['orphanedComics'][0]['id']);
        self::assertNotNull($entityManager->getRepository(Comic::class)->find($orphanedComic->getId()));
        self::assertNotNull($entityManager->getRepository(ComicShare::class)->find($shareId));

        $deleted = $this->deleteJson('/api/comics', [
            'comicIds' => [$orphanedComic->getId()],
            'confirmOrphaned' => true,
        ]);

        self::assertResponseIsSuccessful();
        self::assertSame([$orphanedComic->getId()], $deleted['orphanedComicIds']);
        self::assertNull($entityManager->getRepository(Comic::class)->find($orphanedComic->getId()));

        // The share outlives the comic as a tombstone, so its recipient is told
        // why the comic went away instead of finding it silently missing.
        $entityManager->clear();
        $tombstone = $entityManager->getRepository(ComicShare::class)->find($shareId);
        self::assertNotNull($tombstone);
        self::assertTrue($tombstone->isTombstoned());
        self::assertSame(ComicShare::REASON_OWNER_DELETED, $tombstone->getTombstoneReason());
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
