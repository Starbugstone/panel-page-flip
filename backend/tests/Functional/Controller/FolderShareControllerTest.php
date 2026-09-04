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
use App\Tests\Functional\InvitationLinkAssertions;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\MailerAssertionsTrait;

use function Zenstruck\Foundry\Persistence\unproxy;

/**
 * Sharing a whole folder in one act.
 *
 * The folder is resolved to comics and each still becomes its own durable
 * {@see ComicShare}, but the pending offer is one batch: one email, one link and
 * one recipient decision. What this file is mostly about is the two places that
 * could quietly widen access — a folder holding somebody else's comic, and a
 * client naming ids the server did not resolve itself.
 */
final class FolderShareControllerTest extends AbstractApiTestCase
{
    use MailerAssertionsTrait;
    use InvitationLinkAssertions;

    public function testThePreviewWalksTheWholeSubtreeAndOffersOnlyWhatTheOwnerMayShare(): void
    {
        $owner = $this->createAndLoginUser(['email' => 'dragonball@example.com']);
        $stranger = $this->user(UserFactory::createOne());

        $dragonBall = $this->folder($owner, 'DragonBall');
        $z = $this->folder($owner, 'Z', $dragonBall);
        $elsewhere = $this->folder($owner, 'Elsewhere');

        $volumeOne = $this->file($owner, $this->comic(ComicFactory::new()->ownedBy($owner)->create(['title' => 'Volume 1'])), $dragonBall);
        $volumeTwo = $this->file($owner, $this->comic(ComicFactory::new()->ownedBy($owner)->create(['title' => 'Volume 2'])), $z);
        $this->file($owner, $this->comic(ComicFactory::new()->ownedBy($owner)->create(['title' => 'Unrelated'])), $elsewhere);

        // Filed into DragonBall by this viewer, but owned by somebody else. A
        // folder is a view rather than a container, so being filed next to a
        // comic the owner may share does not make this one shareable.
        $received = $this->comic(ComicFactory::new()->ownedBy($stranger)->create(['title' => 'Borrowed']));
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

    public function testAFolderShareCreatesOneBatchAcceptedWithOneLink(): void
    {
        $recipient = $this->user(UserFactory::createOne(['email' => 'friend@example.com']));
        $owner = $this->createAndLoginUser(['email' => 'owner@example.com', 'name' => 'Goku']);
        $dragonBall = $this->folder($owner, 'DragonBall');
        $z = $this->folder($owner, 'Z', $dragonBall);

        $first = $this->file($owner, $this->comic(ComicFactory::new()->ownedBy($owner)->create(['title' => 'Volume 1'])), $dragonBall);
        $second = $this->file($owner, $this->comic(ComicFactory::new()->ownedBy($owner)->create(['title' => 'Volume 2'])), $z);

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
        self::assertCount(1, $this->invitationUrlsFromEmail());
        $token = $this->invitationTokenFromEmail();

        // The per-comic grants share one pending decision boundary.
        $sharedByMe = $this->getJson('/api/shares/shared-by-me')['sharedByMe'];
        self::assertEqualsCanonicalizing(
            [$first->getId(), $second->getId()],
            array_column($sharedByMe, 'comicId')
        );
        foreach ($sharedByMe as $share) {
            self::assertSame(ComicShare::STATUS_PENDING, $share['status']);
            self::assertNotNull($share['invitationBatchId']);
            self::assertSame('DragonBall', $share['invitationBatchName']);
            self::assertSame(2, $share['invitationBatchSize']);
        }

        $this->loginAs($recipient);
        self::assertSame(1, $this->getJson('/api/shares/summary')['pendingInvitations']);

        $accepted = $this->postJson('/api/shares/invitations/'.$token.'/accept');
        self::assertResponseIsSuccessful();
        self::assertSame(2, $accepted['acceptedCount']);
        self::assertSame(
            [ComicShare::STATUS_ACCEPTED, ComicShare::STATUS_ACCEPTED],
            array_column($this->getJson('/api/shares/shared-with-me')['sharedWithMe'], 'status')
        );
        self::assertSame(0, $this->getJson('/api/shares/summary')['pendingInvitations']);
    }

    /**
     * The preview is a preview. The share names the folder again and the server
     * walks it fresh, so a comic filed out of it in between does not go.
     */
    public function testTheFolderIsResolvedAtSendTimeRatherThanFromWhatTheClientSaw(): void
    {
        $owner = $this->createAndLoginUser();
        $folder = $this->folder($owner, 'Manga');
        $staying = $this->file($owner, $this->comic(ComicFactory::new()->ownedBy($owner)->create(['title' => 'Staying'])), $folder);
        $leaving = $this->file($owner, $this->comic(ComicFactory::new()->ownedBy($owner)->create(['title' => 'Leaving'])), $folder);

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

    public function testOneAgeConfirmationUnlocksAMixedFolderBeforeTheSingleAccept(): void
    {
        $recipient = $this->user(UserFactory::createOne(['email' => 'adult-reader@example.com']));
        $owner = $this->createAndLoginUser(['email' => 'mixed-owner@example.com']);
        $folder = $this->folder($owner, 'Mixed shelf');
        $this->file(
            $owner,
            $this->comic(ComicFactory::new()->ownedBy($owner)->create(['title' => 'All ages'])),
            $folder
        );
        $this->file(
            $owner,
            $this->comic(ComicFactory::new()->ownedBy($owner)->create([
                'title' => 'Hidden adult title',
                'explicitContent' => true,
            ])),
            $folder
        );

        $this->postJson('/api/shares/invitations/bulk', [
            'folderId' => $folder->getId(),
            'email' => (string) $recipient->getEmail(),
            'senderResponsibilityAccepted' => true,
        ]);
        self::assertResponseStatusCodeSame(201);
        $token = $this->invitationTokenFromEmail();

        $this->loginAs($recipient);
        $preview = $this->getJson('/api/shares/invitations/'.$token)['invitation'];
        self::assertTrue($preview['isFolderBatch']);
        self::assertSame(2, $preview['comicCount']);
        self::assertTrue($preview['requiresAdultConfirmation']);
        self::assertNull($preview['comicTitle']);

        $this->postJson('/api/shares/invitations/'.$token.'/accept');
        self::assertResponseStatusCodeSame(403);
        self::assertSame(
            [ComicShare::STATUS_PENDING, ComicShare::STATUS_PENDING],
            array_column($this->getJson('/api/shares/shared-with-me')['sharedWithMe'], 'status')
        );

        $this->postJson('/api/shares/invitations/'.$token.'/confirm-adult', ['adultConfirmed' => true]);
        self::assertResponseIsSuccessful();
        self::assertFalse($this->getJson('/api/shares/invitations/'.$token)['invitation']['requiresAdultConfirmation']);

        $accepted = $this->postJson('/api/shares/invitations/'.$token.'/accept');
        self::assertResponseIsSuccessful();
        self::assertSame(2, $accepted['acceptedCount']);
    }

    /**
     * A large folder still gets the same single invitation link; its email
     * summarises titles so the notice stays readable.
     */
    public function testALargeFolderShareSummarisesInsteadOfListingEveryLink(): void
    {
        $owner = $this->createAndLoginUser(['name' => 'Bulma']);
        $folder = $this->folder($owner, 'Everything');

        $count = ComicShareService::MAX_LISTED_INVITATIONS + 1;
        for ($volume = 1; $volume <= $count; ++$volume) {
            $this->file(
                $owner,
                $this->comic(ComicFactory::new()->ownedBy($owner)->create(['title' => 'Volume ' . $volume])),
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

        // One capability for the folder, never one per comic.
        preg_match_all('#/share/invitation/([A-Za-z0-9_-]+)#', html_entity_decode($body), $matches);
        self::assertCount(1, array_unique($matches[1]));
        self::assertStringNotContainsString('/sharing', html_entity_decode($body));
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
            $comicIds[] = (int) $this->comic(ComicFactory::new()->ownedBy($owner)->create())->getId();
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
            $this->file($owner, $this->comic(ComicFactory::new()->ownedBy($owner)->create()), $folder);
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

    public function testAnAdminMayShareAFolderLargerThanTheOrdinaryCeiling(): void
    {
        $admin = $this->createAndLoginAdmin(['email' => 'admin@example.com']);
        $folder = $this->folder($admin, 'Archive');

        for ($i = 0; $i <= SharingWorkflowService::MAX_FOLDER_COMICS; ++$i) {
            $this->file($admin, $this->comic(ComicFactory::new()->ownedBy($admin)->create()), $folder);
        }

        $preview = $this->getJson('/api/shares/folders/' . $folder->getId() . '/comics');
        self::assertNull($preview['limit']);

        $payload = $this->postJson('/api/shares/invitations/bulk', [
            'folderId' => $folder->getId(),
            'email' => 'friend@example.com',
            'senderResponsibilityAccepted' => true,
        ]);

        self::assertResponseStatusCodeSame(201);
        self::assertSame(SharingWorkflowService::MAX_FOLDER_COMICS + 1, $payload['created']);
    }

    public function testNamingAFolderAndAListAtOnceIsRefusedRatherThanResolvedByPrecedence(): void
    {
        $owner = $this->createAndLoginUser();
        $folder = $this->folder($owner, 'Manga');
        $filed = $this->file($owner, $this->comic(ComicFactory::new()->ownedBy($owner)->create()), $folder);
        $loose = $this->comic(ComicFactory::new()->ownedBy($owner)->create());

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
        $stranger = $this->user(UserFactory::createOne());

        $empty = $this->folder($owner, 'Empty');
        $borrowed = $this->folder($owner, 'Borrowed');
        $received = $this->comic(ComicFactory::new()->ownedBy($stranger)->create());
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
        $stranger = $this->user(UserFactory::createOne());
        $strangersFolder = $this->folder($stranger, 'Private');
        $this->file($stranger, $this->comic(ComicFactory::new()->ownedBy($stranger)->create()), $strangersFolder);

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
        $this->file($owner, $this->comic(ComicFactory::new()->ownedBy($owner)->create()), $folder);

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
        $owner = $this->user(UserFactory::createOne());
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

    private function user(object $user): User
    {
        $user = unproxy($user);
        if (!$user instanceof User) {
            throw new \LogicException('Expected the user factory to create a User.');
        }

        return $user;
    }

    private function comic(object $comic): Comic
    {
        $comic = unproxy($comic);
        if (!$comic instanceof Comic) {
            throw new \LogicException('Expected the comic factory to create a Comic.');
        }

        return $comic;
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }
}
