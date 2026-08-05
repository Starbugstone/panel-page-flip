<?php

namespace App\Tests\Functional\Controller;

use App\Entity\Comic;
use App\Entity\Tag;
use App\Tests\Factory\ComicFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Functional\AbstractApiTestCase;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;

final class GlobalTagVisibilityTest extends AbstractApiTestCase
{
    public function testLibraryHidingTagsAreExcludedUntilThatTagIsExplicitlyFiltered(): void
    {
        $owner = $this->createAndLoginUser();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);

        $marvel = (new Tag())->setName('Marvel')->setIsGlobal(true);
        $hidden = (new Tag())->setName('Hidden')->setIsGlobal(true)->setHideFromLibrary(true);
        $entityManager->persist($marvel);
        $entityManager->persist($hidden);

        $visibleComic = ComicFactory::new()->ownedBy($owner)->create(['title' => 'Visible'])->object();
        $hiddenComic = ComicFactory::new()->ownedBy($owner)->create(['title' => 'Private'])->object();
        $visibleComic->addTag($marvel);
        $hiddenComic->addTag($marvel)->addTag($hidden);
        $entityManager->flush();

        $defaultLibrary = $this->getJson('/api/comics');
        self::assertResponseIsSuccessful();
        self::assertSame(['Visible'], array_column($defaultLibrary['comics'], 'title'));

        $ordinaryFilter = $this->getJson('/api/comics?tags=Marvel');
        self::assertResponseIsSuccessful();
        self::assertSame(['Visible'], array_column($ordinaryFilter['comics'], 'title'));

        $hiddenFilter = $this->getJson('/api/comics?tags=Hidden');
        self::assertResponseIsSuccessful();
        self::assertSame(['Private'], array_column($hiddenFilter['comics'], 'title'));
        $hiddenTag = null;
        foreach ($hiddenFilter['comics'][0]['tags'] as $tag) {
            if (($tag['name'] ?? null) === 'Hidden') {
                $hiddenTag = $tag;
                break;
            }
        }
        self::assertNotNull($hiddenTag);
        self::assertTrue($hiddenTag['hideFromLibrary']);
    }

    public function testGlobalTagsAreAvailableToEveryUserButOnlyAdminsCanCreateThem(): void
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $globalTag = (new Tag())->setName('Manga')->setIsGlobal(true);
        $entityManager->persist($globalTag);
        $entityManager->flush();

        $this->createAndLoginUser();
        $payload = $this->getJson('/api/tags');
        self::assertResponseIsSuccessful();
        self::assertSame('Manga', $payload['tags'][0]['name']);
        self::assertTrue($payload['tags'][0]['isGlobal']);
        self::assertNull($payload['tags'][0]['creator']);

        $this->postJson('/api/tags', ['name' => 'Restricted', 'isGlobal' => true]);
        self::assertResponseStatusCodeSame(403);
    }

    /**
     * The application already rejects a duplicate global name, so this covers
     * the database backstop underneath it: UNIQUE (name, creator_id) cannot
     * catch this case because both rows have a NULL creator_id, and the
     * generated global_name_key column is what closes that hole. It is only
     * reachable by writing past the controller.
     */
    public function testDatabaseRejectsTwoGlobalTagsSharingANameRegardlessOfCase(): void
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist((new Tag())->setName('Marvel')->setIsGlobal(true));
        $entityManager->flush();

        $entityManager->persist((new Tag())->setName('marvel')->setIsGlobal(true));

        $this->expectException(UniqueConstraintViolationException::class);
        $entityManager->flush();
    }

    public function testTwoPersonalTagsMayShareTheSameNameAcrossDifferentCreators(): void
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $first = UserFactory::new()->create()->object();
        $second = UserFactory::new()->create()->object();

        // global_name_key stays NULL for personal tags, so the unique index
        // must not treat these as a collision.
        $entityManager->persist((new Tag())->setName('Favourites')->setCreator($first));
        $entityManager->persist((new Tag())->setName('Favourites')->setCreator($second));
        $entityManager->flush();

        self::assertCount(2, $entityManager->getRepository(Tag::class)->findBy(['name' => 'Favourites']));
    }

    public function testDeletingPersonalTagAlsoDetachesItFromComics(): void
    {
        $owner = $this->createAndLoginUser();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $tag = (new Tag())->setName('Temporary')->setCreator($owner);
        $comic = ComicFactory::new()->ownedBy($owner)->create()->object();
        $comic->addTag($tag);
        $entityManager->persist($tag);
        $entityManager->flush();

        $this->deleteJson('/api/tags/' . $tag->getId());
        self::assertResponseIsSuccessful();

        $entityManager->clear();
        self::assertNull($entityManager->find(Tag::class, $tag->getId()));
        self::assertCount(0, $entityManager->find(Comic::class, $comic->getId())->getTags());
    }
}
