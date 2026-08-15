<?php

namespace App\Tests\Functional\Controller;

use App\Entity\Comic;
use App\Entity\ComicShare;
use App\Entity\LibraryFolder;
use App\Entity\LibraryFolderItem;
use App\Entity\Tag;
use App\Entity\User;
use App\Tests\Factory\ComicFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Functional\AbstractApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

final class LibraryFolderControllerTest extends AbstractApiTestCase
{
    public function testFolderCrudBuildsAPrivateHierarchy(): void
    {
        $owner = $this->createAndLoginUser();

        $marvel = $this->postJson('/api/library/folders', ['name' => ' Marvel '])['folder'];
        self::assertResponseStatusCodeSame(201);
        $spiderMan = $this->postJson('/api/library/folders', [
            'name' => 'Spider-Man',
            'parentId' => $marvel['id'],
        ])['folder'];
        self::assertResponseStatusCodeSame(201);

        self::assertSame('Marvel', $marvel['name']);
        self::assertSame($marvel['id'], $spiderMan['parentId']);

        $renamed = $this->patchJson('/api/library/folders/' . $spiderMan['id'], ['name' => 'Spider-Verse']);
        self::assertResponseIsSuccessful();
        self::assertSame('Spider-Verse', $renamed['folder']['name']);

        $folders = $this->getJson('/api/library/folders')['folders'];
        self::assertSame(['Marvel', 'Spider-Verse'], array_column($folders, 'name'));

        $other = UserFactory::createOne()->object();
        $this->loginAs($other);
        self::assertSame([], $this->getJson('/api/library/folders')['folders']);
        $this->patchJson('/api/library/folders/' . $marvel['id'], ['name' => 'Stolen']);
        self::assertResponseStatusCodeSame(404);

        $this->loginAs($owner);
        $this->deleteJson('/api/library/folders/' . $spiderMan['id']);
        self::assertResponseIsSuccessful();
    }

    public function testFolderNamesDuplicatesCyclesAndDepthAreValidated(): void
    {
        $this->createAndLoginUser();
        $root = $this->postJson('/api/library/folders', ['name' => 'Marvel'])['folder'];

        foreach (['', 'bad/name', 'bad\\name', "bad\nname"] as $name) {
            $this->postJson('/api/library/folders', ['name' => $name]);
            self::assertResponseStatusCodeSame(400);
        }

        $this->postJson('/api/library/folders', ['name' => 'marvel']);
        self::assertResponseStatusCodeSame(422);

        $parent = $root;
        for ($depth = 2; $depth <= 10; $depth++) {
            $parent = $this->postJson('/api/library/folders', [
                'name' => 'Level ' . $depth,
                'parentId' => $parent['id'],
            ])['folder'];
            self::assertResponseStatusCodeSame(201);
        }
        $this->postJson('/api/library/folders', ['name' => 'Too deep', 'parentId' => $parent['id']]);
        self::assertResponseStatusCodeSame(422);

        $this->patchJson('/api/library/folders/' . $root['id'], ['parentId' => $parent['id']]);
        self::assertResponseStatusCodeSame(422);
    }

    public function testMovingComicsIsSparseViewerSpecificAndFolderFiltered(): void
    {
        $owner = $this->createAndLoginUser();
        $rootComic = ComicFactory::new()->ownedBy($owner)->create(['title' => 'At root'])->object();
        $filedComic = ComicFactory::new()->ownedBy($owner)->create(['title' => 'Filed'])->object();
        $folder = $this->postJson('/api/library/folders', ['name' => 'Marvel'])['folder'];

        $moved = $this->postJson('/api/library/folders/move-comics', [
            'comicIds' => [$filedComic->getId()],
            'folderId' => $folder['id'],
        ]);
        self::assertResponseIsSuccessful();
        self::assertSame($folder['id'], $moved['folderId']);

        $all = $this->getJson('/api/comics')['comics'];
        $locations = array_column($all, 'libraryFolderId', 'title');
        self::assertNull($locations['At root']);
        self::assertSame($folder['id'], $locations['Filed']);
        self::assertSame(['At root'], array_column($this->getJson('/api/comics?folder=root')['comics'], 'title'));
        self::assertSame(['Filed'], array_column($this->getJson('/api/comics?folder=' . $folder['id'])['comics'], 'title'));

        $this->postJson('/api/library/folders/move-comics', [
            'comicIds' => [$filedComic->getId()],
            'folderId' => null,
        ]);
        self::assertResponseIsSuccessful();
        self::assertCount(0, $this->items()->findBy(['user' => $owner, 'comic' => $filedComic]));
        self::assertCount(2, $this->getJson('/api/comics?folder=root')['comics']);

        // Idempotent root moves do not create rows just to represent root.
        $this->postJson('/api/library/folders/move-comics', [
            'comicIds' => [$rootComic->getId(), $filedComic->getId()],
            'folderId' => null,
        ]);
        self::assertResponseIsSuccessful();
        self::assertCount(0, $this->items()->findBy(['user' => $owner]));
    }

    public function testARecipientCanFileASharedComicWithoutChangingTheOwner(): void
    {
        $owner = UserFactory::createOne()->object();
        $recipient = UserFactory::createOne()->object();
        $comic = ComicFactory::new()->ownedBy($owner)->create(['title' => 'Shared'])->object();
        $share = (new ComicShare($comic, $owner, (string) $recipient->getEmail()))->markAccepted($recipient);
        $this->em()->persist($share);
        $this->em()->flush();

        $this->loginAs($recipient);
        $folder = $this->postJson('/api/library/folders', ['name' => 'Received'])['folder'];
        $this->postJson('/api/library/folders/move-comics', [
            'comicIds' => [$comic->getId()],
            'folderId' => $folder['id'],
        ]);
        self::assertResponseIsSuccessful();
        self::assertSame($folder['id'], $this->getJson('/api/comics')['comics'][0]['libraryFolderId']);

        $this->loginAs($owner);
        self::assertNull($this->getJson('/api/comics')['comics'][0]['libraryFolderId']);
        self::assertCount(1, $this->items()->findAll());
    }

    public function testMoveRejectsForeignFoldersAndInaccessibleComicsWithoutDisclosure(): void
    {
        $attacker = $this->createAndLoginUser();
        $foreignUser = UserFactory::createOne()->object();
        $foreignComic = ComicFactory::new()->ownedBy($foreignUser)->create()->object();
        $foreignFolder = $this->folder($foreignUser, 'Private folder');

        $payload = $this->postJson('/api/library/folders/move-comics', [
            'comicIds' => [$foreignComic->getId()],
            'folderId' => $foreignFolder->getId(),
        ]);
        self::assertResponseStatusCodeSame(404);
        self::assertSame('Folder not found.', $payload['message']);

        $ownFolder = $this->postJson('/api/library/folders', ['name' => 'Mine'])['folder'];
        $payload = $this->postJson('/api/library/folders/move-comics', [
            'comicIds' => [$foreignComic->getId()],
            'folderId' => $ownFolder['id'],
        ]);
        self::assertResponseStatusCodeSame(404);
        self::assertSame('One or more comics were not found in your library.', $payload['message']);
        self::assertCount(0, $this->items()->findBy(['user' => $attacker]));
    }

    public function testAnExplicitlyFoundHiddenComicCanStillBeOrganised(): void
    {
        $owner = $this->createAndLoginUser();
        $comic = ComicFactory::new()->ownedBy($owner)->create(['title' => 'Hidden but accessible'])->object();
        $tag = (new Tag())->setName('Hidden')->setIsGlobal(true)->setHideFromLibrary(true);
        $comic->addTag($tag);
        $this->em()->persist($tag);
        $this->em()->flush();
        $folder = $this->postJson('/api/library/folders', ['name' => 'Private'])['folder'];

        self::assertSame([], $this->getJson('/api/comics')['comics']);
        self::assertSame(['Hidden but accessible'], array_column($this->getJson('/api/comics?tags=Hidden')['comics'], 'title'));

        $this->postJson('/api/library/folders/move-comics', [
            'comicIds' => [$comic->getId()],
            'folderId' => $folder['id'],
        ]);
        self::assertResponseIsSuccessful();
        self::assertCount(1, $this->items()->findBy(['user' => $owner, 'comic' => $comic]));
        // Folder navigation itself still cannot reveal the hidden comic.
        self::assertSame([], $this->getJson('/api/comics?folder=' . $folder['id'])['comics']);
    }

    public function testConfirmedSubtreeDeletionMovesComicsToTheParentAndNeverDeletesThem(): void
    {
        $owner = $this->createAndLoginUser();
        $comic = ComicFactory::new()->ownedBy($owner)->create(['title' => 'Safe'])->object();
        $parent = $this->postJson('/api/library/folders', ['name' => 'Parent'])['folder'];
        $child = $this->postJson('/api/library/folders', ['name' => 'Child', 'parentId' => $parent['id']])['folder'];
        $grandchild = $this->postJson('/api/library/folders', ['name' => 'Grandchild', 'parentId' => $child['id']])['folder'];
        $this->postJson('/api/library/folders/move-comics', [
            'comicIds' => [$comic->getId()],
            'folderId' => $grandchild['id'],
        ]);

        $warning = $this->deleteJson('/api/library/folders/' . $child['id']);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('folder_deletion_confirmation_required', $warning['code']);
        self::assertSame(2, $warning['summary']['folderCount']);
        self::assertSame(1, $warning['summary']['comicCount']);
        self::assertNotNull($this->em()->find(Comic::class, $comic->getId()));

        $confirmed = $this->deleteJson('/api/library/folders/' . $child['id'], ['confirm' => true]);
        self::assertResponseIsSuccessful();
        self::assertTrue($confirmed['deleted']);
        $this->em()->clear();
        self::assertNotNull($this->em()->find(Comic::class, $comic->getId()));
        $item = $this->items()->findOneBy(['user' => $owner, 'comic' => $comic]);
        self::assertSame($parent['id'], $item?->getFolder()?->getId());
        self::assertNull($this->em()->find(LibraryFolder::class, $child['id']));
        self::assertNull($this->em()->find(LibraryFolder::class, $grandchild['id']));
    }

    private function folder(User $owner, string $name, ?LibraryFolder $parent = null): LibraryFolder
    {
        $folder = (new LibraryFolder())->setOwner($owner)->setName($name)->setParent($parent);
        $this->em()->persist($folder);
        $this->em()->flush();

        return $folder;
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    /** @return \Doctrine\Persistence\ObjectRepository<LibraryFolderItem> */
    private function items(): \Doctrine\Persistence\ObjectRepository
    {
        return $this->em()->getRepository(LibraryFolderItem::class);
    }
}
