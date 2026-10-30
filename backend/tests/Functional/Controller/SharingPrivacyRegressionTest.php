<?php

namespace App\Tests\Functional\Controller;

use App\Entity\Comic;
use App\Entity\ComicReadingProgress;
use App\Entity\ComicShare;
use App\Entity\LibraryFolder;
use App\Entity\LibraryFolderItem;
use App\Entity\User;
use App\Enum\ReadingDirection;
use App\Tests\Factory\ComicFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Functional\AbstractApiTestCase;
use App\Tests\Functional\InvitationLinkAssertions;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\ORM\EntityManagerInterface;
use DoctrineMigrations\Version20261030210000;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\MailerAssertionsTrait;

/**
 * What one side of a share may learn about the other, and what the owner's
 * decisions still mean after the code or link that started it has gone round.
 */
final class SharingPrivacyRegressionTest extends AbstractApiTestCase
{
    use MailerAssertionsTrait;
    use InvitationLinkAssertions;

    private const OWNER_EMAIL = 'owner-private@example.com';
    private const REDEEMER_EMAIL = 'redeemer-private@example.com';

    /* ---------------------------------------------------------------------- */
    /* Replaying a code                                                        */
    /* ---------------------------------------------------------------------- */

    public function testReplayingASpentCodeDoesNotUndoARevocation(): void
    {
        [$owner, $redeemer, $comic, $code] = $this->redeemedCode();

        $this->loginAs($owner);
        $this->postJson(sprintf('/api/shares/%d/revoke', $this->shareFor($comic)->getId()));
        self::assertResponseIsSuccessful();

        $this->loginAs($redeemer);
        $replay = $this->postJson('/api/shares/content-codes/redeem', ['code' => $code]);

        // Reported rather than refused: this person did spend a use.
        self::assertResponseIsSuccessful();
        self::assertTrue($replay['alreadyRedeemed']);
        self::assertSame(0, $replay['claimed']);
        self::assertSame(0, $replay['alreadyHeld']);
        self::assertSame('no_longer_held', $replay['results'][0]['status']);

        $this->getJson(sprintf('/api/comics/%d', $comic->getId()));
        self::assertResponseStatusCodeSame(403);
        self::assertSame(ComicShare::STATUS_REVOKED, $this->shareFor($comic)->getStatus());
    }

    /** Deleting the record of a revocation must not make room for a new share. */
    public function testReplayingASpentCodeDoesNotRecreateADeletedShare(): void
    {
        [$owner, $redeemer, $comic, $code] = $this->redeemedCode();

        $this->loginAs($owner);
        $shareId = $this->shareFor($comic)->getId();
        $this->postJson(sprintf('/api/shares/%d/revoke', $shareId));
        $this->deleteJson(sprintf('/api/shares/%d', $shareId));
        self::assertResponseIsSuccessful();

        $this->loginAs($redeemer);
        $replay = $this->postJson('/api/shares/content-codes/redeem', ['code' => $code]);

        self::assertResponseIsSuccessful();
        self::assertSame(0, $replay['claimed']);
        $this->em()->clear();
        self::assertNull($this->em()->getRepository(ComicShare::class)->findOneBy(['comic' => $comic->getId()]));
    }

    /** A replay is not a way back in after the recipient said no, either. */
    public function testReplayingASpentCodeDoesNotReopenADeclinedInvitation(): void
    {
        $owner = UserFactory::createOne(['email' => self::OWNER_EMAIL]);
        $comic = ComicFactory::new()->ownedBy($owner)->explicit()->create();
        $this->loginAs($owner);
        $code = $this->issueCode($comic);

        $redeemer = UserFactory::createOne(['email' => self::REDEEMER_EMAIL]);
        $this->loginAs($redeemer);
        $this->postJson('/api/shares/content-codes/redeem', ['code' => $code]);
        $this->postJson(sprintf('/api/shares/%d/decline', $this->shareFor($comic)->getId()));
        self::assertResponseIsSuccessful();

        $this->postJson('/api/shares/content-codes/redeem', ['code' => $code]);

        self::assertResponseIsSuccessful();
        self::assertSame(ComicShare::STATUS_DECLINED, $this->shareFor($comic)->getStatus());
    }

    /** An email change must not turn "what you hold" into "nothing". */
    public function testAReplayStillFindsTheShareAfterAnAddressChange(): void
    {
        [, $redeemer, $comic, $code] = $this->redeemedCode();

        $this->em()->getConnection()->executeStatement(
            'UPDATE `user` SET email = ? WHERE id = ?',
            ['renamed-private@example.com', $redeemer->getId()]
        );
        $this->em()->clear();
        $this->loginAs($this->em()->find(User::class, $redeemer->getId()));

        $replay = $this->postJson('/api/shares/content-codes/redeem', ['code' => $code]);

        self::assertResponseIsSuccessful();
        self::assertSame(1, $replay['alreadyHeld']);
        self::assertSame(ComicShare::STATUS_ACCEPTED, $this->shareFor($comic)->getStatus());
    }

    /** The second code predates the revocation, so it does not overrule it. */
    public function testACodeOlderThanARevocationDoesNotUndoIt(): void
    {
        [$owner, $redeemer, $comic] = $this->redeemedCode();

        $this->loginAs($owner);
        $olderCode = $this->issueCode($comic);
        $this->postJson(sprintf('/api/shares/%d/revoke', $this->shareFor($comic)->getId()));
        $this->em()->getConnection()->executeStatement(
            'UPDATE share_claim_code SET created_at = created_at - INTERVAL 1 DAY'
        );
        $this->em()->clear();

        $this->loginAs($redeemer);
        $this->postJson('/api/shares/content-codes/redeem', ['code' => $olderCode]);

        self::assertResponseStatusCodeSame(409);
        self::assertSame(ComicShare::STATUS_REVOKED, $this->shareFor($comic)->getStatus());

        $this->loginAs($owner);
        $codes = $this->getJson('/api/shares/content-codes')['codes'];
        self::assertSame(1, array_sum(array_column($codes, 'timesUsed')), 'The refusal spent nothing.');
    }

    /** Re-offering a comic after revoking it stays possible, by a new code. */
    public function testACodeIssuedAfterARevocationIsAFreshOffer(): void
    {
        [$owner, $redeemer, $comic] = $this->redeemedCode();

        $this->loginAs($owner);
        $this->postJson(sprintf('/api/shares/%d/revoke', $this->shareFor($comic)->getId()));
        $this->em()->getConnection()->executeStatement(
            'UPDATE comic_share SET revoked_at = revoked_at - INTERVAL 1 DAY'
        );
        $this->em()->clear();
        $newCode = $this->issueCode($comic);

        $this->loginAs($redeemer);
        $payload = $this->postJson('/api/shares/content-codes/redeem', ['code' => $newCode]);

        self::assertResponseIsSuccessful();
        self::assertSame(1, $payload['claimed']);
        self::assertSame(ComicShare::STATUS_ACCEPTED, $this->shareFor($comic)->getStatus());
    }

    /* ---------------------------------------------------------------------- */
    /* The owner's address                                                     */
    /* ---------------------------------------------------------------------- */

    public function testOnlyTheOwnerAndAnAdministratorReadTheOwnersAddressFromAComic(): void
    {
        [$owner, , $comic] = $this->redeemedCode();

        $asRecipient = $this->getJson(sprintf('/api/comics/%d', $comic->getId()));
        self::assertResponseIsSuccessful();
        self::assertSame('Owner', $asRecipient['comic']['owner']['name']);
        self::assertArrayNotHasKey('email', $asRecipient['comic']['owner']);
        self::assertStringNotContainsString(self::OWNER_EMAIL, (string) json_encode($asRecipient));

        $this->loginAs($owner);
        $asOwner = $this->getJson(sprintf('/api/comics/%d', $comic->getId()));
        self::assertSame(self::OWNER_EMAIL, $asOwner['comic']['owner']['email']);

        $this->createAndLoginAdmin();
        $asAdmin = $this->getJson(sprintf('/api/comics/%d', $comic->getId()));
        self::assertResponseIsSuccessful();
        self::assertSame(self::OWNER_EMAIL, $asAdmin['comic']['owner']['email']);
    }

    public function testANamelessOwnerIsKnownByTheirUsernameAndNeverByTheirAddress(): void
    {
        [$owner, , $comic] = $this->redeemedCode(['name' => null, 'username' => 'nameless_owner']);

        $received = $this->getJson('/api/shares/shared-with-me');
        self::assertResponseIsSuccessful();
        self::assertSame('@nameless_owner', $received['sharedWithMe'][0]['ownerName']);
        self::assertSame('@nameless_owner', $received['sharedWithMe'][0]['ownerLabel']);
        self::assertStringNotContainsString(self::OWNER_EMAIL, (string) json_encode($received));

        $detail = $this->getJson(sprintf('/api/comics/%d', $comic->getId()));
        self::assertSame('@nameless_owner', $detail['comic']['sharedBy']['name']);
        self::assertStringNotContainsString(self::OWNER_EMAIL, (string) json_encode($detail));

        self::assertSame($owner->getId(), $detail['comic']['owner']['id']);
    }

    public function testAnEmailedInvitationPreviewNeverNamesANamelessOwnersAddress(): void
    {
        $owner = UserFactory::createOne(['email' => self::OWNER_EMAIL, 'name' => null, 'username' => 'nameless_owner']);
        $comic = ComicFactory::new()->ownedBy($owner)->create();
        $this->loginAs($owner);
        $this->postJson('/api/shares/invitations/bulk', [
            'comicIds' => [$comic->getId()],
            'email' => 'friend@example.com',
            'senderResponsibilityAccepted' => true,
        ]);
        self::assertResponseStatusCodeSame(201);
        $token = $this->invitationTokenFromEmail();

        $this->browser()->request('GET', '/api/shares/invitations/'.$token);

        self::assertResponseIsSuccessful();
        $body = (string) $this->browser()->getResponse()->getContent();
        self::assertStringNotContainsString(self::OWNER_EMAIL, $body);
        self::assertSame('@nameless_owner', json_decode($body, true)['invitation']['ownerName']);
    }

    public function testTheMigrationRemovesAddressesFromExistingSnapshots(): void
    {
        $connection = $this->em()->getConnection();
        $nameless = UserFactory::createOne(['email' => 'nameless@example.com', 'name' => null, 'username' => 'nameless_one']);
        $named = UserFactory::createOne(['email' => 'named@example.com', 'name' => 'Named Owner', 'username' => 'named_one']);
        $rows = [
            'current address, no name' => $this->shareWithSnapshot($nameless, 'nameless@example.com'),
            'address in another case' => $this->shareWithSnapshot($nameless, 'NAMELESS@example.com'),
            'address they have since changed' => $this->shareWithSnapshot($named, 'old-address@example.com'),
            'account gone' => $this->shareWithSnapshot($named, 'gone@example.com'),
            'a real name' => $this->shareWithSnapshot($named, 'An Older Name'),
            'already a handle' => $this->shareWithSnapshot($nameless, '@nameless_one'),
        ];
        $connection->executeStatement('UPDATE comic_share SET owner_id = NULL WHERE id = ?', [$rows['account gone']]);

        $expected = [
            'current address, no name' => '@nameless_one',
            'address in another case' => '@nameless_one',
            'address they have since changed' => 'Named Owner',
            'account gone' => 'A former user',
            'a real name' => 'An Older Name',
            'already a handle' => '@nameless_one',
        ];

        // Twice, because a migration has to be safe on data it already cleaned.
        for ($run = 0; $run < 2; ++$run) {
            require_once dirname(__DIR__, 3).'/migrations/Version20261030210000.php';
            $migration = new Version20261030210000($connection, new NullLogger());
            $migration->up(new Schema());
            foreach ($migration->getSql() as $query) {
                $connection->executeStatement($query->getStatement());
            }

            foreach ($rows as $label => $id) {
                self::assertSame(
                    $expected[$label],
                    $connection->fetchOne('SELECT owner_name_snapshot FROM comic_share WHERE id = ?', [$id]),
                    $label
                );
            }
        }
    }

    /* ---------------------------------------------------------------------- */
    /* The data export                                                         */
    /* ---------------------------------------------------------------------- */

    public function testAnOwnersExportKeepsARedeemersAddressHidden(): void
    {
        [$owner] = $this->redeemedCode();
        $this->loginAs($owner);

        $export = $this->getJson('/api/privacy/export');

        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString(self::REDEEMER_EMAIL, (string) json_encode($export));
        self::assertNull($export['sharesGranted'][0]['recipientEmail']);
        // What the Sharing page offers in its place, so the row is still legible.
        self::assertStringContainsString('@', $export['sharesGranted'][0]['recipientLabel']);
    }

    public function testAnOwnersExportStillNamesAnAddressTheyTypedThemselves(): void
    {
        $owner = $this->createAndLoginUser();
        $comic = ComicFactory::new()->ownedBy($owner)->create();
        $this->postJson('/api/shares/invitations/bulk', [
            'comicIds' => [$comic->getId()],
            'email' => 'typed@example.com',
            'senderResponsibilityAccepted' => true,
        ]);
        self::assertResponseStatusCodeSame(201);

        $export = $this->getJson('/api/privacy/export');

        self::assertSame('typed@example.com', $export['sharesGranted'][0]['recipientEmail']);
    }

    public function testARecipientsExportWithholdsWhatTheAgeGateWithholds(): void
    {
        $owner = UserFactory::createOne();
        $comic = ComicFactory::new()->ownedBy($owner)->explicit()->create(['title' => 'Behind The Gate']);
        $recipient = $this->createAndLoginUser(['email' => 'not-yet@example.com']);
        $share = new ComicShare($this->entity(Comic::class, $comic), $this->entity(User::class, $owner), 'not-yet@example.com');
        $share->markPending(new \DateTimeImmutable('+1 month'));
        $this->em()->persist($share);
        // The two records that can outlive a comic being marked explicit.
        $me = $this->entity(User::class, $recipient);
        $folder = (new LibraryFolder())->setOwner($me)->setName('Mine');
        $this->em()->persist($folder);
        $this->em()->persist((new LibraryFolderItem())->setUser($me)->setComic($share->getComic())->setFolder($folder));
        $this->em()->persist(
            (new ComicReadingProgress())->setUser($me)->setComic($share->getComic())->setCurrentPage(3)->setLastReadAt(new \DateTimeImmutable())
        );
        $this->em()->flush();

        $export = $this->getJson('/api/privacy/export');

        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('Behind The Gate', (string) json_encode($export));
        self::assertNull($export['sharesReceived'][0]['comicId']);
        self::assertNull($export['sharesReceived'][0]['comicTitle']);
        self::assertTrue($export['sharesReceived'][0]['explicitContent']);
        self::assertNull($export['readingProgress'][0]['comicId']);
        self::assertNull($export['libraryFolderPlacements'][0]['comicId']);
        self::assertSame($folder->getId(), $export['libraryFolderPlacements'][0]['folderId']);

        $this->postJson(sprintf('/api/shares/%d/confirm-adult', $share->getId()), ['adultConfirmed' => true]);
        self::assertResponseIsSuccessful();

        $confirmed = $this->getJson('/api/privacy/export');
        self::assertSame($comic->getId(), $confirmed['sharesReceived'][0]['comicId']);
        self::assertSame('Behind The Gate', $confirmed['sharesReceived'][0]['comicTitle']);
        self::assertSame('Behind The Gate', $confirmed['readingProgress'][0]['comicTitle']);
        self::assertSame('Behind The Gate', $confirmed['libraryFolderPlacements'][0]['comicTitle']);
    }

    public function testTheExportCarriesUsernameFoldersPlacementsAndStructuredMetadata(): void
    {
        $user = $this->createAndLoginUser(['username' => 'export_reader', 'name' => 'Export Reader']);
        $me = $this->entity(User::class, $user);
        $comic = $this->entity(Comic::class, ComicFactory::new()->ownedBy($user)->create(['title' => 'Structured']));
        $comic
            ->setSeries('The Long Arc')
            ->setIssueNumber('12')
            ->setVolume(3)
            ->setLanguageCode('fr')
            ->setReadingDirection(ReadingDirection::RightToLeft)
            ->setCreators([['role' => 'writer', 'name' => 'A. Writer']])
            ->setMetadataOrigin('comicvine', 'cv-1');

        $parent = (new LibraryFolder())->setOwner($me)->setName('Parent');
        $child = (new LibraryFolder())->setOwner($me)->setName('Child')->setParent($parent);
        $this->em()->persist($parent);
        $this->em()->persist($child);
        $this->em()->persist((new LibraryFolderItem())->setUser($me)->setComic($comic)->setFolder($child));
        $this->em()->flush();

        $export = $this->getJson('/api/privacy/export');

        self::assertResponseIsSuccessful();
        self::assertSame('export_reader', $export['account']['username']);

        $folders = array_column($export['libraryFolders'], null, 'name');
        self::assertNull($folders['Parent']['parentId']);
        self::assertSame($parent->getId(), $folders['Child']['parentId']);
        self::assertSame(
            ['comicId' => $comic->getId(), 'comicTitle' => 'Structured', 'folderId' => $child->getId()],
            array_intersect_key($export['libraryFolderPlacements'][0], array_flip(['comicId', 'comicTitle', 'folderId']))
        );

        $exported = $export['comics'][0];
        self::assertSame('The Long Arc', $exported['series']);
        self::assertSame('12', $exported['issueNumber']);
        self::assertSame(3, $exported['volume']);
        self::assertSame('fr', $exported['languageCode']);
        self::assertSame(ReadingDirection::RightToLeft->value, $exported['readingDirection']);
        self::assertSame('A. Writer', $exported['creators'][0]['name']);
        self::assertSame('comicvine', $exported['metadataOrigin']['provider']);

        // Credentials stay out, and so does the internal storage path.
        $encoded = strtolower((string) json_encode($export));
        self::assertStringNotContainsString('password', $encoded);
        self::assertStringNotContainsString('filepath', $encoded);
    }

    /* ---------------------------------------------------------------------- */
    /* Folder invitations                                                      */
    /* ---------------------------------------------------------------------- */

    /** @dataProvider withdrawals */
    public function testWithdrawingTheFirstGrantLeavesTheFolderLinkWorking(string $how): void
    {
        [$owner, $recipient, $token, $first, $second] = $this->folderInvitation();

        $this->withdraw($how, $owner, $first);

        $this->loginAs($recipient);
        $preview = $this->getJson('/api/shares/invitations/'.$token);
        self::assertResponseIsSuccessful();
        self::assertSame(1, $preview['invitation']['comicCount']);

        $accepted = $this->postJson('/api/shares/invitations/'.$token.'/accept');
        self::assertResponseIsSuccessful();
        self::assertSame(1, $accepted['acceptedCount']);

        $this->getJson(sprintf('/api/comics/%d', $second->getId()));
        self::assertResponseIsSuccessful();
    }

    /** @return iterable<string, array{string}> */
    public static function withdrawals(): iterable
    {
        yield 'the owner revokes it' => ['revoke'];
        yield 'the owner stops sharing that comic' => ['stop'];
        yield 'an administrator revokes it' => ['admin'];
        yield 'the owner deletes that comic' => ['delete'];
    }

    public function testALinkDiesWithTheLastGrantOnOffer(): void
    {
        [$owner, $recipient, $token, $first, $second] = $this->folderInvitation();

        $this->withdraw('revoke', $owner, $first);
        $this->withdraw('revoke', $owner, $second);

        $this->loginAs($recipient);
        $this->getJson('/api/shares/invitations/'.$token);
        self::assertResponseStatusCodeSame(410);
    }

    /** A withdrawn explicit comic must not hold the rest behind an age gate. */
    public function testAWithdrawnExplicitGrantDoesNotGateTheRestOfTheFolder(): void
    {
        [$owner, $recipient, $token, $first] = $this->folderInvitation(firstIsExplicit: true);

        $this->withdraw('revoke', $owner, $first);

        $this->loginAs($recipient);
        $preview = $this->getJson('/api/shares/invitations/'.$token);
        self::assertResponseIsSuccessful();
        self::assertFalse($preview['invitation']['explicitContent']);
        self::assertFalse($preview['invitation']['requiresAdultConfirmation']);

        $this->postJson('/api/shares/invitations/'.$token.'/accept');
        self::assertResponseIsSuccessful();
    }

    /* ---------------------------------------------------------------------- */
    /* Handed-out codes                                                        */
    /* ---------------------------------------------------------------------- */

    public function testAnOldLiveCodeStaysListedAndCanStillBeWithdrawn(): void
    {
        $owner = $this->createAndLoginUser();
        $comic = ComicFactory::new()->ownedBy($owner)->create();
        $ids = [];
        for ($i = 0; $i < 32; ++$i) {
            $ids[] = $this->postJson('/api/shares/comic-codes', [
                'comicIds' => [$comic->getId()],
                'maxUses' => 1,
                'senderResponsibilityAccepted' => true,
            ])['contentCode']['id'];
            self::assertResponseStatusCodeSame(201);
        }

        $allLive = array_column($this->getJson('/api/shares/content-codes')['codes'], 'id');
        self::assertCount(32, $allLive);
        self::assertSame(array_reverse($ids), $allLive, 'Newest first.');

        // Thirty-one newer dead codes used to push the oldest live one off the list.
        foreach (array_slice($ids, 1) as $id) {
            $this->deleteJson(sprintf('/api/shares/content-codes/%d', $id));
            self::assertResponseIsSuccessful();
        }

        $listed = $this->getJson('/api/shares/content-codes')['codes'];
        self::assertCount(31, $listed, 'Every live code, plus the newest thirty dead ones.');
        $oldest = array_column($listed, null, 'id')[$ids[0]] ?? null;
        self::assertNotNull($oldest);
        self::assertTrue($oldest['isRedeemable']);

        $this->deleteJson(sprintf('/api/shares/content-codes/%d', $ids[0]));
        self::assertResponseIsSuccessful();
    }

    /* ---------------------------------------------------------------------- */

    /** @return array{0: User, 1: User, 2: Comic, 3: string} owner, redeemer, comic, code */
    private function redeemedCode(array $ownerAttributes = []): array
    {
        $owner = UserFactory::createOne($ownerAttributes + ['email' => self::OWNER_EMAIL, 'name' => 'Owner']);
        $comic = ComicFactory::new()->ownedBy($owner)->create(['title' => 'Handed Out']);
        $this->loginAs($owner);
        $code = $this->issueCode($comic);

        $redeemer = UserFactory::createOne(['email' => self::REDEEMER_EMAIL, 'name' => 'Redeemer']);
        $this->loginAs($redeemer);
        $this->postJson('/api/shares/content-codes/redeem', ['code' => $code]);
        self::assertResponseIsSuccessful();

        return [$owner, $redeemer, $comic, $code];
    }

    private function issueCode(object $comic): string
    {
        $code = $this->postJson('/api/shares/comic-codes', [
            'comicIds' => [$comic->getId()],
            'maxUses' => 1,
            'senderResponsibilityAccepted' => true,
        ])['code'];
        self::assertResponseStatusCodeSame(201);

        return $code;
    }

    private function shareFor(object $comic): ComicShare
    {
        $this->em()->clear();
        $share = $this->em()->getRepository(ComicShare::class)->findOneBy(['comic' => $comic->getId()]);
        self::assertNotNull($share);

        return $share;
    }

    private function shareWithSnapshot(object $owner, string $snapshot): int
    {
        $comic = ComicFactory::new()->ownedBy($owner)->create();
        $share = new ComicShare($this->entity(Comic::class, $comic), $this->entity(User::class, $owner), uniqid('r', true).'@example.com');
        $this->em()->persist($share);
        $this->em()->flush();
        $this->em()->getConnection()->executeStatement(
            'UPDATE comic_share SET owner_name_snapshot = ? WHERE id = ?',
            [$snapshot, $share->getId()]
        );

        return (int) $share->getId();
    }

    /** @return array{0: User, 1: User, 2: string, 3: object, 4: object} the anchor comic is the first */
    private function folderInvitation(bool $firstIsExplicit = false): array
    {
        $recipient = UserFactory::createOne(['email' => 'folder-reader@example.com']);
        $owner = $this->createAndLoginUser();
        $me = $this->entity(User::class, $owner);
        $folder = (new LibraryFolder())->setOwner($me)->setName('Arc');
        $this->em()->persist($folder);

        $comics = [];
        foreach ([$firstIsExplicit, false] as $index => $explicit) {
            $comics[$index] = ComicFactory::new()->ownedBy($owner)->create([
                'title' => 'Issue '.($index + 1),
                'explicitContent' => $explicit,
            ]);
            $this->em()->persist(
                (new LibraryFolderItem())->setUser($me)->setComic($this->entity(Comic::class, $comics[$index]))->setFolder($folder)
            );
        }
        $this->em()->flush();

        $this->postJson('/api/shares/invitations/bulk', [
            'folderId' => $folder->getId(),
            'email' => 'folder-reader@example.com',
            'senderResponsibilityAccepted' => true,
        ]);
        self::assertResponseStatusCodeSame(201);
        $token = $this->invitationTokenFromEmail();

        // The link hangs from the row that was written first.
        $rows = $this->em()->getRepository(ComicShare::class)->findBy(['recipientEmailNormalized' => 'folder-reader@example.com'], ['id' => 'ASC']);
        $anchorComicId = $rows[0]->getComic()?->getId();
        [$first, $second] = $anchorComicId === $comics[0]->getId() ? [$comics[0], $comics[1]] : [$comics[1], $comics[0]];

        return [$owner, $recipient, $token, $first, $second];
    }

    private function withdraw(string $how, object $owner, object $comic): void
    {
        $this->loginAs($owner);
        $shareId = $this->shareFor($comic)->getId();

        match ($how) {
            'revoke' => $this->postJson(sprintf('/api/shares/%d/revoke', $shareId)),
            'stop' => $this->deleteJson(sprintf('/api/shares/comics/%d', $comic->getId())),
            'delete' => $this->deleteJson(sprintf('/api/comics/%d', $comic->getId())),
            'admin' => (function () use ($shareId): void {
                $this->createAndLoginAdmin();
                $this->postJson(sprintf('/api/admin/shares/%d/revoke', $shareId));
            })(),
        };
        self::assertResponseIsSuccessful();
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private function entity(string $class, object $entity): object
    {
        $found = $this->em()->find($class, $entity->getId());
        self::assertNotNull($found);

        return $found;
    }

    private function em(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }
}
