<?php

namespace App\Tests\Functional\Controller;

use App\Entity\Comic;
use App\Entity\ComicShare;
use App\Entity\LibraryFolder;
use App\Entity\LibraryFolderItem;
use App\Entity\User;
use App\Service\ComicShareService;
use App\Service\SharingWorkflowService;
use App\Tests\Factory\ComicFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Functional\AbstractApiTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\MailerAssertionsTrait;

/**
 * Sharing a whole folder in one act.
 *
 * A convenience over the ordinary invitation model rather than a second way in:
 * the folder is resolved to comics, each of those still becomes its own
 * {@see ComicShare}, and every one of them is answered, expired and withdrawn on
 * its own. What this file is mostly about is the two places that convenience
 * could quietly widen access — a folder holding somebody else's comic, and a
 * client naming ids the server did not resolve itself.
 */
final class FolderShareControllerTest extends AbstractApiTestCase
{
    use MailerAssertionsTrait;

    public function testThePreviewWalksTheWholeSubtreeAndOffersOnlyWhatTheOwnerMayShare(): void
    {
        $owner = $this->createAndLoginUser(['email' => 'dragonball@example.com']);
        $stranger = UserFactory::createOne()->object();

        $dragonBall = $this->folder($owner, 'DragonBall');
        $z = $this->folder($owner, 'Z', $dragonBall);
        $elsewhere = $this->folder($owner, 'Elsewhere');

        $volumeOne = $this->file($owner, ComicFactory::new()->ownedBy($owner)->create(['title' => 'Volume 1'])->object(), $dragonBall);
        $volumeTwo = $this->file($owner, ComicFactory::new()->ownedBy($owner)->create(['title' => 'Volume 2'])->object(), $z);
        $this->file($owner, ComicFactory::new()->ownedBy($owner)->create(['title' => 'Unrelated'])->object(), $elsewhere);

        // Filed into DragonBall by this viewer, but owned by somebody else. A
        // folder is a view rather than a container, so being filed next to a
        // comic the owner may share does not make this one shareable.
        $received = ComicFactory::new()->ownedBy($stranger)->create(['title' => 'Borrowed'])->object();
        $this->em()->persist((new ComicShare($received, $stranger, (string) $owner->getEmail()))->markAccepted($owner));
        $this->em()->flush();
        $this->file($owner, $received, $z);

        $preview = $this->getJson('/api/shares/folders/' . $dragonBall->getId() . '/comics');

        self::assertResponseIsSuccessful();
        self::assertSame('DragonBall', $preview['folder']['name']);
        self::assertEqualsCanonicalizing(
            [$volumeOne->getId(), $volumeTwo->getId()],
            $preview['comicIds']
        );
        self::assertSame(2, $preview['comicCount']);
        // The folder and its one subfolder.
        self::assertSame(2, $preview['folderCount']);
        // Counted, never named: which comic cannot be passed on is a fact about
        // the library rather than part of the share being prepared.
        self::assertSame(1, $preview['unshareableCount']);
        self::assertSame(SharingWorkflowService::MAX_FOLDER_COMICS, $preview['limit']);
    }

    public function testAFolderShareCreatesOneOrdinaryInvitationPerComicInTheSubtree(): void
    {
        $owner = $this->createAndLoginUser(['email' => 'owner@example.com', 'name' => 'Goku']);
        $dragonBall = $this->folder($owner, 'DragonBall');
        $z = $this->folder($owner, 'Z', $dragonBall);

        $first = $this->file($owner, ComicFactory::new()->ownedBy($owner)->create(['title' => 'Volume 1'])->object(), $dragonBall);
        $second = $this->file($owner, ComicFactory::new()->ownedBy($owner)->create(['title' => 'Volume 2'])->object(), $z);

        $payload = $this->postJson('/api/shares/invitations/bulk', [
            'folderId' => $dragonBall->getId(),
            'email' => 'friend@example.com',
            'senderResponsibilityAccepted' => true,
        ]);

        self::assertResponseStatusCodeSame(201);
        self::assertSame(2, $payload['created']);
        self::assertSame(['created', 'created'], array_column($payload['results'], 'status'));

        // One notice, and it says where the comics came from. Asserted before
        // any further request: the mailer assertions read what the last one
        // collected, so reading the Sharing page first would clear this.
        self::assertEmailCount(1);
        $email = self::getMailerMessage();
        self::assertSame('Goku shared 2 comics from "DragonBall" with you!', $email->getSubject());
        self::assertStringContainsString('DragonBall', $email->getHtmlBody());

        // Ordinary relationships, indistinguishable from the ones a hand-picked
        // bulk share makes: nothing about a folder survives into the grant.
        $sharedByMe = $this->getJson('/api/shares/shared-by-me')['sharedByMe'];
        self::assertEqualsCanonicalizing(
            [$first->getId(), $second->getId()],
            array_column($sharedByMe, 'comicId')
        );
        foreach ($sharedByMe as $group) {
            self::assertSame(ComicShare::STATUS_PENDING, $group['recipients'][0]['status']);
        }
    }

    /**
     * The preview is a preview. The share names the folder again and the server
     * walks it fresh, so a comic filed out of it in between does not go.
     */
    public function testTheFolderIsResolvedAtSendTimeRatherThanFromWhatTheClientSaw(): void
    {
        $owner = $this->createAndLoginUser();
        $folder = $this->folder($owner, 'Manga');
        $staying = $this->file($owner, ComicFactory::new()->ownedBy($owner)->create(['title' => 'Staying'])->object(), $folder);
        $leaving = $this->file($owner, ComicFactory::new()->ownedBy($owner)->create(['title' => 'Leaving'])->object(), $folder);

        $preview = $this->getJson('/api/shares/folders/' . $folder->getId() . '/comics');
        self::assertCount(2, $preview['comicIds']);

        $this->postJson('/api/library/folders/move-comics', [
            'comicIds' => [$leaving->getId()],
            'folderId' => null,
        ]);
        self::assertResponseIsSuccessful();

        $payload = $this->postJson('/api/shares/invitations/bulk', [
            'folderId' => $folder->getId(),
            'email' => 'friend@example.com',
            'senderResponsibilityAccepted' => true,
        ]);

        self::assertResponseStatusCodeSame(201);
        self::assertSame(1, $payload['created']);
        self::assertSame(
            [$staying->getId()],
            array_column($this->getJson('/api/shares/shared-by-me')['sharedByMe'], 'comicId')
        );
    }

    /**
     * Past the listing ceiling the notice becomes a summary. The shares are
     * untouched by that — it is the email that changes, and only because two
     * hundred buttons is not a message anybody reads.
     */
    public function testALargeFolderShareSummarisesInsteadOfListingEveryLink(): void
    {
        $owner = $this->createAndLoginUser(['name' => 'Bulma']);
        $folder = $this->folder($owner, 'Everything');

        $count = ComicShareService::MAX_LISTED_INVITATIONS + 1;
        for ($volume = 1; $volume <= $count; ++$volume) {
            $this->file(
                $owner,
                ComicFactory::new()->ownedBy($owner)->create(['title' => 'Volume ' . $volume])->object(),
                $folder
            );
        }

        $payload = $this->postJson('/api/shares/invitations/bulk', [
            'folderId' => $folder->getId(),
            'email' => 'friend@example.com',
            'senderResponsibilityAccepted' => true,
        ]);

        self::assertResponseStatusCodeSame(201);
        self::assertSame($count, $payload['created']);

        self::assertEmailCount(1);
        $body = self::getMailerMessage()->getHtmlBody();

        // No invitation link is minted into a summary at all: a capability that
        // was never rendered never entered the message.
        preg_match_all('#/share/invitation/([A-Za-z0-9_-]+)#', html_entity_decode($body), $matches);
        self::assertSame([], $matches[1]);
        self::assertStringContainsString('/sharing', html_entity_decode($body));
        // It still says what it is about, without claiming to list all of it.
        self::assertStringContainsString('Volume 1', $body);
        self::assertStringContainsString('and ' . ($count - 10) . ' more', $body);
    }

    /**
     * The higher folder ceiling is offered to a request the server resolves
     * itself, and only to that. A hand-assembled list of the same ids keeps the
     * ceiling it always had.
     */
    public function testTheHigherCeilingIsNotReachableByListingIds(): void
    {
        $owner = $this->createAndLoginUser();
        $comicIds = [];
        for ($i = 0; $i <= SharingWorkflowService::MAX_BULK_COMICS; ++$i) {
            $comicIds[] = (int) ComicFactory::new()->ownedBy($owner)->create()->object()->getId();
        }

        $this->postJson('/api/shares/invitations/bulk', [
            'comicIds' => $comicIds,
            'email' => 'friend@example.com',
            'senderResponsibilityAccepted' => true,
        ]);

        self::assertResponseStatusCodeSame(400);
        self::assertEmailCount(0);
    }

    public function testAFolderLargerThanTheCeilingIsRefusedWholeRatherThanTruncated(): void
    {
        $owner = $this->createAndLoginUser();
        $folder = $this->folder($owner, 'Too much');

        $overTheLimit = SharingWorkflowService::MAX_FOLDER_COMICS + 1;
        for ($i = 0; $i < $overTheLimit; ++$i) {
            $this->file($owner, ComicFactory::new()->ownedBy($owner)->create()->object(), $folder);
        }

        $payload = $this->postJson('/api/shares/invitations/bulk', [
            'folderId' => $folder->getId(),
            'email' => 'friend@example.com',
            'senderResponsibilityAccepted' => true,
        ]);

        self::assertResponseStatusCodeSame(400);
        self::assertStringContainsString((string) SharingWorkflowService::MAX_FOLDER_COMICS, $payload['message']);
        // Nothing half-shared: a folder share that cannot be made whole is not
        // made at all.
        self::assertSame([], $this->getJson('/api/shares/shared-by-me')['sharedByMe']);
        self::assertEmailCount(0);
    }

    public function testNamingAFolderAndAListAtOnceIsRefusedRatherThanResolvedByPrecedence(): void
    {
        $owner = $this->createAndLoginUser();
        $folder = $this->folder($owner, 'Manga');
        $filed = $this->file($owner, ComicFactory::new()->ownedBy($owner)->create()->object(), $folder);
        $loose = ComicFactory::new()->ownedBy($owner)->create()->object();

        $this->postJson('/api/shares/invitations/bulk', [
            'folderId' => $folder->getId(),
            'comicIds' => [$loose->getId()],
            'email' => 'friend@example.com',
            'senderResponsibilityAccepted' => true,
        ]);

        self::assertResponseStatusCodeSame(400);
        self::assertSame([], $this->getJson('/api/shares/shared-by-me')['sharedByMe']);
        self::assertNotNull($filed->getId());
    }

    public function testAnEmptyFolderAndAFolderOfBorrowedComicsAreBothRefused(): void
    {
        $owner = $this->createAndLoginUser(['email' => 'empty@example.com']);
        $stranger = UserFactory::createOne()->object();

        $empty = $this->folder($owner, 'Empty');
        $borrowed = $this->folder($owner, 'Borrowed');
        $received = ComicFactory::new()->ownedBy($stranger)->create()->object();
        $this->em()->persist((new ComicShare($received, $stranger, (string) $owner->getEmail()))->markAccepted($owner));
        $this->em()->flush();
        $this->file($owner, $received, $borrowed);

        foreach ([$empty, $borrowed] as $folder) {
            $payload = $this->postJson('/api/shares/invitations/bulk', [
                'folderId' => $folder->getId(),
                'email' => 'friend@example.com',
                'senderResponsibilityAccepted' => true,
            ]);

            self::assertResponseStatusCodeSame(400);
            // The same sentence either way. The sender is looking at a folder
            // and the answer they need does not depend on which of the two
            // reasons it is.
            self::assertSame('There is nothing in that folder that you can share.', $payload['message']);
        }

        self::assertEmailCount(0);
    }

    /**
     * A folder id that is not the caller's is missing, not forbidden — the same
     * answer the folder API gives, because confirming that an id exists would
     * say something about another account's library.
     */
    public function testAForeignFolderIsMissingToBothThePreviewAndTheShare(): void
    {
        $stranger = UserFactory::createOne()->object();
        $strangersFolder = $this->folder($stranger, 'Private');
        $this->file($stranger, ComicFactory::new()->ownedBy($stranger)->create()->object(), $strangersFolder);

        $this->createAndLoginUser();

        $this->getJson('/api/shares/folders/' . $strangersFolder->getId() . '/comics');
        self::assertResponseStatusCodeSame(404);

        $this->postJson('/api/shares/invitations/bulk', [
            'folderId' => $strangersFolder->getId(),
            'email' => 'friend@example.com',
            'senderResponsibilityAccepted' => true,
        ]);
        self::assertResponseStatusCodeSame(404);

        // A folder that never existed answers identically, so the pair cannot be
        // used to map out which ids are real.
        $this->getJson('/api/shares/folders/99999999/comics');
        self::assertResponseStatusCodeSame(404);

        self::assertEmailCount(0);
    }

    public function testAFolderShareStillRequiresTheSenderResponsibilityAcknowledgement(): void
    {
        $owner = $this->createAndLoginUser();
        $folder = $this->folder($owner, 'Manga');
        $this->file($owner, ComicFactory::new()->ownedBy($owner)->create()->object(), $folder);

        $this->postJson('/api/shares/invitations/bulk', [
            'folderId' => $folder->getId(),
            'email' => 'friend@example.com',
        ]);

        self::assertResponseStatusCodeSame(400);
        self::assertSame([], $this->getJson('/api/shares/shared-by-me')['sharedByMe']);
        self::assertEmailCount(0);
    }

    public function testThePreviewRequiresAuthentication(): void
    {
        $owner = UserFactory::createOne()->object();
        $folder = $this->folder($owner, 'Manga');

        $this->getJson('/api/shares/folders/' . $folder->getId() . '/comics');

        self::assertResponseStatusCodeSame(401);
    }

    private function folder(User $owner, string $name, ?LibraryFolder $parent = null): LibraryFolder
    {
        $folder = (new LibraryFolder())->setOwner($owner)->setName($name)->setParent($parent);
        $this->em()->persist($folder);
        $this->em()->flush();

        return $folder;
    }

    private function file(User $viewer, Comic $comic, LibraryFolder $folder): Comic
    {
        $item = (new LibraryFolderItem())->setUser($viewer)->setComic($comic)->setFolder($folder);
        $this->em()->persist($item);
        $this->em()->flush();

        return $comic;
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }
}
