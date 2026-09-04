<?php

namespace App\Tests\Functional\Controller;

use App\Entity\Comic;
use App\Entity\ComicShare;
use App\Entity\ShareInvitationToken;
use App\Entity\User;
use App\Repository\ComicShareRepository;
use App\Service\ComicShareService;
use App\Service\ExpiredShareCleanupService;
use App\Service\SecurityAuditLogger;
use App\Tests\Factory\ComicFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Functional\AbstractApiTestCase;
use App\Tests\Functional\InvitationLinkAssertions;
use App\Tests\Functional\SecurityLogAssertions;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The sharing model, exercised end to end.
 *
 * The rules being pinned here are the ones that make sharing safe: accepting
 * copies nothing, access is only ever granted by an accepted share, and every
 * way of losing it — declining, revoking, deleting — takes effect immediately
 * and leaves the recipient an explanation rather than a silent absence.
 */
final class ShareControllerTest extends AbstractApiTestCase
{
    use InvitationLinkAssertions;
    use SecurityLogAssertions;

    public function testAcceptingAShareCreatesNoSecondComicOrFile(): void
    {
        $owner = UserFactory::createOne();
        $comic = ComicFactory::new()->ownedBy($owner)->create();
        $recipient = $this->createAndLoginUser(['email' => 'recipient@test.local']);

        $comicsBefore = ComicFactory::repository()->count();
        $share = $this->createAcceptedShare($comic, $owner, $recipient);

        self::assertSame(ComicShare::STATUS_ACCEPTED, $share->getStatus());
        // The whole point of the rework: one comic, one file, one owner.
        self::assertSame($comicsBefore, ComicFactory::repository()->count());
        self::assertSame($owner->getId(), $share->getComic()?->getOwner()?->getId());
    }

    public function testARecipientCannotReachAComicBeforeAccepting(): void
    {
        $owner = UserFactory::createOne();
        $comic = ComicFactory::new()->ownedBy($owner)->create([
            'coverImagePath' => 'covers/missing/cover.png',
        ]);
        $recipient = $this->createAndLoginUser(['email' => 'not-yet@test.local']);

        // A pending invitation exists, and grants nothing.
        $this->persistShare($comic, $owner, (string) $recipient->getEmail());

        $this->getJson('/api/comics/' . $comic->getId());
        self::assertResponseStatusCodeSame(403);

        // Refused, not hidden. An invitation is addressed to them by name, so
        // they already know this comic exists; every endpoint says the same
        // thing about it rather than the metadata refusing and the bytes
        // pretending there is nothing there.
        $this->browser()->request('GET', '/api/comics/' . $comic->getId() . '/pages/1');
        self::assertResponseStatusCodeSame(403);

        $this->browser()->request(
            'GET',
            sprintf('/api/comics/cover/%d/%d/cover.png', $owner->getId(), $comic->getId())
        );
        self::assertResponseStatusCodeSame(403);
    }

    public function testAnAcceptedShareUnlocksMetadataAndTheCover(): void
    {
        $owner = UserFactory::createOne();
        $comic = ComicFactory::new()->ownedBy($owner)->create([
            'coverImagePath' => 'covers/missing/cover.png',
        ]);
        $recipient = $this->createAndLoginUser(['email' => 'reader@test.local']);
        $this->createAcceptedShare($comic, $owner, $recipient);

        $payload = $this->getJson('/api/comics/' . $comic->getId());
        self::assertResponseIsSuccessful();
        self::assertTrue($payload['comic']['isShared']);
        self::assertFalse($payload['comic']['isOwner']);
        self::assertSame($owner->getName(), $payload['comic']['sharedBy']['name']);

        // The cover lives under the owner's id, which is not the requester's;
        // access is the voter's decision, not the URL's.
        $this->browser()->request(
            'GET',
            sprintf('/api/comics/cover/%d/%d/cover.png', $owner->getId(), $comic->getId())
        );
        self::assertResponseIsSuccessful();
    }

    public function testAnAcceptedShareAppearsInTheRecipientsNormalCollection(): void
    {
        $owner = UserFactory::createOne();
        $comic = ComicFactory::new()->ownedBy($owner)->create(['title' => 'Batman: Year One']);
        $recipient = $this->createAndLoginUser(['email' => 'collector@test.local']);
        $ownComic = ComicFactory::new()->ownedBy($recipient)->create();
        $this->createAcceptedShare($comic, $owner, $recipient);

        $all = $this->getJson('/api/comics');
        self::assertResponseIsSuccessful();
        self::assertEqualsCanonicalizing(
            [$comic->getId(), $ownComic->getId()],
            array_column($all['comics'], 'id')
        );

        self::assertSame([$ownComic->getId()], array_column($this->getJson('/api/comics?ownership=mine')['comics'], 'id'));
        self::assertSame([$comic->getId()], array_column($this->getJson('/api/comics?ownership=shared')['comics'], 'id'));
    }

    public function testARecipientCannotEditDeleteOrReshareTheOwnersComic(): void
    {
        $owner = UserFactory::createOne();
        $comic = ComicFactory::new()->ownedBy($owner)->create(['title' => 'Untouched']);
        $recipient = $this->createAndLoginUser(['email' => 'nosy@test.local']);
        $this->createAcceptedShare($comic, $owner, $recipient);

        $this->patchJson('/api/comics/' . $comic->getId(), ['title' => 'Hijacked']);
        self::assertResponseStatusCodeSame(403);

        $this->browser()->request('DELETE', '/api/comics/' . $comic->getId(), [], [], $this->csrfHeader());
        self::assertResponseStatusCodeSame(403);

        // The whole request is refused, and one message covers a comic that is
        // not yours and one that does not exist alike — so it cannot be used to
        // find out which ids are real.
        $this->postInvitation((int) $comic->getId(), 'third@test.local');
        self::assertResponseStatusCodeSame(403);

        // Downloading the archive is owner-only: a recipient reads through the
        // reader and never takes a copy away.
        $this->browser()->request('GET', '/api/comics/' . $comic->getId() . '/download');
        self::assertResponseStatusCodeSame(403);

        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();
        self::assertSame('Untouched', $entityManager->find(Comic::class, $comic->getId())?->getTitle());
    }

    public function testEachUserKeepsTheirOwnReadingPosition(): void
    {
        $owner = UserFactory::createOne();
        $comic = ComicFactory::new()->ownedBy($owner)->create(['pageCount' => 40]);
        $recipient = UserFactory::createOne(['email' => 'independent@test.local']);

        $this->loginAs($recipient);
        $this->createAcceptedShare($comic, $owner, $recipient);
        $this->postJson('/api/comics/' . $comic->getId() . '/progress', ['currentPage' => 12]);
        self::assertResponseIsSuccessful();

        $this->loginAs($owner);
        $this->postJson('/api/comics/' . $comic->getId() . '/progress', ['currentPage' => 30]);
        self::assertResponseIsSuccessful();
        self::assertSame(30, $this->getJson('/api/comics/' . $comic->getId())['comic']['readingProgress']['currentPage']);

        $this->loginAs($recipient);
        self::assertSame(12, $this->getJson('/api/comics/' . $comic->getId())['comic']['readingProgress']['currentPage']);
    }

    public function testRemovingASharedComicOnlyHidesItFromThatRecipient(): void
    {
        $owner = UserFactory::createOne();
        $comic = ComicFactory::new()->ownedBy($owner)->create();
        $recipient = $this->createAndLoginUser(['email' => 'tidy@test.local']);
        $share = $this->createAcceptedShare($comic, $owner, $recipient);

        $this->postJson('/api/shares/' . $share->getId() . '/remove');
        self::assertResponseIsSuccessful();
        self::assertSame([], $this->getJson('/api/comics')['comics']);

        // Hidden, not given up: the access record survives and can be restored.
        $this->postJson('/api/shares/' . $share->getId() . '/restore');
        self::assertResponseIsSuccessful();
        self::assertSame([$comic->getId()], array_column($this->getJson('/api/comics')['comics'], 'id'));

        // And the owner still has their comic throughout.
        $this->loginAs($owner);
        self::assertSame([$comic->getId()], array_column($this->getJson('/api/comics')['comics'], 'id'));
    }

    public function testRevokingAccessTakesEffectImmediately(): void
    {
        $owner = UserFactory::createOne();
        $comic = ComicFactory::new()->ownedBy($owner)->create();
        $recipient = UserFactory::createOne(['email' => 'revoked@test.local']);

        $this->loginAs($recipient);
        $share = $this->createAcceptedShare($comic, $owner, $recipient);
        $this->getJson('/api/comics/' . $comic->getId());
        self::assertResponseIsSuccessful();

        $this->loginAs($owner);
        $this->postJson('/api/shares/' . $share->getId() . '/revoke');
        self::assertResponseIsSuccessful();

        $this->loginAs($recipient);
        $this->getJson('/api/comics/' . $comic->getId());
        self::assertResponseStatusCodeSame(403);
        // A revoked share is still a share they remember being given, so the
        // refusal is plain rather than a pretence that the comic never existed.
        $this->browser()->request('GET', '/api/comics/' . $comic->getId() . '/pages/1');
        self::assertResponseStatusCodeSame(403);
        self::assertSame([], $this->getJson('/api/comics')['comics']);
    }

    public function testStoppingSharingRevokesEveryRecipientAtOnce(): void
    {
        $owner = UserFactory::createOne();
        $comic = ComicFactory::new()->ownedBy($owner)->create();
        $first = UserFactory::createOne(['email' => 'first@test.local']);
        $second = UserFactory::createOne(['email' => 'second@test.local']);

        $this->loginAs($first);
        $this->createAcceptedShare($comic, $owner, $first);
        $this->loginAs($second);
        $this->createAcceptedShare($comic, $owner, $second);

        $this->loginAs($owner);
        $payload = $this->deleteJson('/api/shares/comics/' . $comic->getId());
        self::assertResponseIsSuccessful();
        self::assertSame(2, $payload['revoked']);

        foreach ([$first, $second] as $recipient) {
            $this->loginAs($recipient);
            $this->getJson('/api/comics/' . $comic->getId());
            self::assertResponseStatusCodeSame(403);
        }
    }

    /**
     * Stopping sharing is addressed by comic id, so it can be asked about any
     * comic at all. A stranger gets the answer an unused id gets; a recipient,
     * who can already name the comic, gets the refusal.
     */
    public function testStoppingSharingTellsAStrangerNothingAboutTheComic(): void
    {
        $owner = UserFactory::createOne();
        $comic = ComicFactory::new()->ownedBy($owner)->create();

        $this->createAndLoginUser(['email' => 'passer-by@test.local']);
        $this->deleteJson('/api/shares/comics/' . $comic->getId());
        self::assertResponseStatusCodeSame(404);
        self::assertSame('Comic not found', $this->json()['message']);

        // Byte for byte what an id nobody has ever used answers.
        $this->deleteJson('/api/shares/comics/999666');
        self::assertResponseStatusCodeSame(404);
        self::assertSame('Comic not found', $this->json()['message']);

        $recipient = $this->createAndLoginUser(['email' => 'reader-only@test.local']);
        $this->createAcceptedShare($comic, $owner, $recipient);
        $this->deleteJson('/api/shares/comics/' . $comic->getId());
        self::assertResponseStatusCodeSame(403);
    }

    public function testDeletingTheOriginalLeavesRecipientsATombstone(): void
    {
        $owner = UserFactory::createOne();
        $comic = ComicFactory::new()->ownedBy($owner)->create(['title' => 'Doomed']);
        $comicId = $comic->getId();
        $recipient = UserFactory::createOne(['email' => 'bereaved@test.local']);

        $this->loginAs($recipient);
        $this->createAcceptedShare($comic, $owner, $recipient);

        $this->loginAs($owner);
        $this->browser()->request('DELETE', '/api/comics/' . $comicId, [], [], $this->csrfHeader());
        self::assertResponseIsSuccessful();

        $this->loginAs($recipient);
        $this->getJson('/api/comics/' . $comicId);
        self::assertResponseStatusCodeSame(404);
        self::assertSame([], $this->getJson('/api/comics')['comics']);

        $shares = $this->getJson('/api/shares/shared-with-me')['sharedWithMe'];
        self::assertCount(1, $shares);
        self::assertTrue($shares[0]['isTombstoned']);
        self::assertSame(ComicShare::REASON_OWNER_DELETED, $shares[0]['tombstoneReason']);
        // The tombstone names the comic but offers no way in, and no path to a
        // file that is gone.
        self::assertSame('Doomed', $shares[0]['comicTitle']);
        self::assertNull($shares[0]['comicId']);
        self::assertNull($shares[0]['coverImagePath']);
        self::assertFalse($shares[0]['canRead']);
        self::assertTrue($shares[0]['isDead']);
    }

    public function testADeletedComicLeavesTheOwnersSharingListEntirely(): void
    {
        $owner = UserFactory::createOne();
        $doomed = ComicFactory::new()->ownedBy($owner)->create(['title' => 'Gone']);
        $kept = ComicFactory::new()->ownedBy($owner)->create(['title' => 'Kept']);
        $recipient = UserFactory::createOne(['email' => 'onlooker@test.local']);

        $this->loginAs($recipient);
        $this->createAcceptedShare($doomed, $owner, $recipient);
        $this->createAcceptedShare($kept, $owner, $recipient);

        $this->loginAs($owner);
        self::assertCount(2, $this->getJson('/api/shares/shared-by-me')['sharedByMe']);

        $this->browser()->request('DELETE', '/api/comics/' . $doomed->getId(), [], [], $this->csrfHeader());
        self::assertResponseIsSuccessful();

        // The tombstone belongs to the recipient, as the record of a comic that
        // went away. The owner caused it, has no comic left to manage, and sees
        // nothing of it.
        $shares = $this->getJson('/api/shares/shared-by-me')['sharedByMe'];
        self::assertCount(1, $shares);
        self::assertSame('Kept', $shares[0]['comicTitle']);

        // The recipient still gets their explanation.
        $this->loginAs($recipient);
        $received = $this->getJson('/api/shares/shared-with-me')['sharedWithMe'];
        self::assertCount(1, array_filter($received, static fn (array $s): bool => $s['isTombstoned']));
    }

    public function testTheInvitationPreviewWithholdsTheRecipientAddressFromEveryoneElse(): void
    {
        $owner = UserFactory::createOne();
        $comic = ComicFactory::new()->ownedBy($owner)->create();
        [, $plaintext] = $this->createPendingInvitation($comic, $owner, 'private@test.local');

        // The preview is public, so anyone holding a forwarded link reaches it.
        // Whoever that is must not learn who was invited.
        $this->client->request('GET', '/api/shares/invitations/' . $plaintext, [], [], ['HTTP_ACCEPT' => 'application/json']);
        self::assertResponseIsSuccessful();
        self::assertNull($this->json()['invitation']['recipientEmail']);

        $this->createAndLoginUser(['email' => 'someone-else@test.local']);
        $payload = $this->getJson('/api/shares/invitations/' . $plaintext);
        self::assertFalse($payload['invitation']['isForCurrentUser']);
        self::assertNull($payload['invitation']['recipientEmail']);
        self::assertNull($payload['invitation']['coverImagePath']);

        // Their own address tells the recipient nothing new, so they still get it.
        $this->createAndLoginUser(['email' => 'private@test.local']);
        $payload = $this->getJson('/api/shares/invitations/' . $plaintext);
        self::assertTrue($payload['invitation']['isForCurrentUser']);
        self::assertSame('private@test.local', $payload['invitation']['recipientEmail']);
    }

    public function testAnInvitationLinkCannotBeBurnedByAThirdParty(): void
    {
        $owner = UserFactory::createOne();
        $comic = ComicFactory::new()->ownedBy($owner)->create();
        $recipient = UserFactory::createOne(['email' => 'rightful@test.local']);
        [, $plaintext] = $this->createPendingInvitation($comic, $owner, $recipient->getEmail());

        // Somebody who got hold of the link but is not the recipient.
        $this->createAndLoginUser(['email' => 'thief@test.local']);
        $this->postJson('/api/shares/invitations/' . $plaintext . '/accept');
        self::assertResponseStatusCodeSame(403);

        // The token must not have been spent on the way to that rejection.
        $this->loginAs($recipient);
        $this->postJson('/api/shares/invitations/' . $plaintext . '/accept');
        self::assertResponseIsSuccessful();
    }

    public function testAShareThatIsNotInTheCollectionCannotBeRemovedFromIt(): void
    {
        $owner = UserFactory::createOne();
        $comic = ComicFactory::new()->ownedBy($owner)->create();
        $recipient = $this->createAndLoginUser(['email' => 'premature@test.local']);

        // Pending: never in the collection, so there is nothing to hide.
        $share = $this->persistShare($comic, $owner, (string) $recipient->getEmail());

        $this->postJson('/api/shares/' . $share->getId() . '/remove');
        self::assertResponseStatusCodeSame(410);
    }

    public function testOwnersAreToldHowManyPeopleADeletionWouldCutOff(): void
    {
        $owner = UserFactory::createOne();
        $comic = ComicFactory::new()->ownedBy($owner)->create();
        $recipient = UserFactory::createOne(['email' => 'counted@test.local']);

        $this->loginAs($recipient);
        $this->createAcceptedShare($comic, $owner, $recipient);

        $this->loginAs($owner);
        self::assertSame(1, $this->getJson('/api/comics/' . $comic->getId())['comic']['sharedWithCount']);
    }

    public function testTombstonesCanBeClearedIndividuallyAndInBulkWithoutTouchingLiveShares(): void
    {
        $owner = UserFactory::createOne();
        $doomedFirst = ComicFactory::new()->ownedBy($owner)->create();
        $doomedSecond = ComicFactory::new()->ownedBy($owner)->create();
        $survivor = ComicFactory::new()->ownedBy($owner)->create();
        $recipient = UserFactory::createOne(['email' => 'sweeper@test.local']);

        $this->loginAs($recipient);
        $first = $this->createAcceptedShare($doomedFirst, $owner, $recipient);
        $second = $this->createAcceptedShare($doomedSecond, $owner, $recipient);
        $this->createAcceptedShare($survivor, $owner, $recipient);

        $this->loginAs($owner);
        foreach ([$doomedFirst, $doomedSecond] as $comic) {
            $this->browser()->request('DELETE', '/api/comics/' . $comic->getId(), [], [], $this->csrfHeader());
            self::assertResponseIsSuccessful();
        }

        $this->loginAs($recipient);
        self::assertSame(2, $this->getJson('/api/shares/summary')['deadShares']);

        $single = $this->deleteJson('/api/shares/tombstones', ['shareIds' => [$first->getId()]]);
        self::assertResponseIsSuccessful();
        self::assertSame(1, $single['removed']);

        $bulk = $this->deleteJson('/api/shares/tombstones');
        self::assertResponseIsSuccessful();
        self::assertSame(1, $bulk['removed']);

        // The live share is untouched, and still readable.
        $remaining = $this->getJson('/api/shares/shared-with-me')['sharedWithMe'];
        self::assertCount(1, $remaining);
        self::assertSame($survivor->getId(), $remaining[0]['comicId']);
        self::assertSame([$survivor->getId()], array_column($this->getJson('/api/comics')['comics'], 'id'));

        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();
        self::assertNull($entityManager->find(ComicShare::class, $second->getId()));
    }

    public function testAnotherUsersTombstonesAreOutOfReach(): void
    {
        $owner = UserFactory::createOne();
        $comic = ComicFactory::new()->ownedBy($owner)->create();
        $victim = UserFactory::createOne(['email' => 'victim@test.local']);

        $this->loginAs($victim);
        $share = $this->createAcceptedShare($comic, $owner, $victim);

        $this->loginAs($owner);
        $this->browser()->request('DELETE', '/api/comics/' . $comic->getId(), [], [], $this->csrfHeader());

        // A different account sweeping its own history must not reach this one.
        $this->createAndLoginUser(['email' => 'stranger@test.local']);
        $payload = $this->deleteJson('/api/shares/tombstones');
        self::assertResponseIsSuccessful();
        self::assertSame(0, $payload['removed']);

        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();
        self::assertNotNull($entityManager->find(ComicShare::class, $share->getId()));
    }

    public function testTheInvitationPreviewChangesNothing(): void
    {
        $owner = UserFactory::createOne();
        $comic = ComicFactory::new()->ownedBy($owner)->create(['title' => 'Peeked At']);
        $recipient = UserFactory::createOne(['email' => 'careful@test.local']);
        [$share, $plaintext] = $this->createPendingInvitation($comic, $owner, $recipient->getEmail());

        $this->loginAs($recipient);
        // Twice, as a scanner and then a person would: a GET that accepted or
        // spent the token would break on the second request.
        foreach ([1, 2] as $_) {
            $payload = $this->getJson('/api/shares/invitations/' . $plaintext);
            self::assertResponseIsSuccessful();
            self::assertSame('Peeked At', $payload['invitation']['comicTitle']);
            self::assertTrue($payload['invitation']['isForCurrentUser']);
        }

        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();
        self::assertSame(
            ComicShare::STATUS_PENDING,
            $entityManager->find(ComicShare::class, $share->getId())?->getStatus()
        );

        // And still nothing readable until the invitation is answered.
        $this->getJson('/api/comics/' . $comic->getId());
        self::assertResponseStatusCodeSame(403);
    }

    public function testAnInvitationCanOnlyBeAcceptedByItsRecipient(): void
    {
        $owner = UserFactory::createOne();
        $comic = ComicFactory::new()->ownedBy($owner)->create();
        [, $plaintext] = $this->createPendingInvitation($comic, $owner, 'intended@test.local');

        $this->createAndLoginUser(['email' => 'interloper@test.local']);
        $this->postJson('/api/shares/invitations/' . $plaintext . '/accept');
        self::assertResponseStatusCodeSame(403);

        // Nobody invited them, so as far as the API is concerned there is no
        // such comic — holding a token addressed to somebody else must not
        // confirm that the thing it names is real.
        $this->getJson('/api/comics/' . $comic->getId());
        self::assertResponseStatusCodeSame(404);
    }

    public function testAcceptingAnInvitationSpendsItsToken(): void
    {
        $owner = UserFactory::createOne();
        $comic = ComicFactory::new()->ownedBy($owner)->create();
        $recipient = $this->createAndLoginUser(['email' => 'once@test.local']);
        [, $plaintext] = $this->createPendingInvitation($comic, $owner, $recipient->getEmail());

        $this->postJson('/api/shares/invitations/' . $plaintext . '/accept');
        self::assertResponseIsSuccessful();

        // The same link again is a duplicate open, not a second acceptance.
        $this->postJson('/api/shares/invitations/' . $plaintext . '/accept');
        self::assertResponseStatusCodeSame(409);
    }

    public function testDecliningAnInvitationGrantsNothing(): void
    {
        $owner = UserFactory::createOne();
        $comic = ComicFactory::new()->ownedBy($owner)->create();
        $recipient = $this->createAndLoginUser(['email' => 'nothanks@test.local']);
        [$share, $plaintext] = $this->createPendingInvitation($comic, $owner, $recipient->getEmail());

        $this->postJson('/api/shares/invitations/' . $plaintext . '/decline');
        self::assertResponseIsSuccessful();

        $this->getJson('/api/comics/' . $comic->getId());
        self::assertResponseStatusCodeSame(403);

        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();
        self::assertSame(
            ComicShare::STATUS_DECLINED,
            $entityManager->find(ComicShare::class, $share->getId())?->getStatus()
        );
    }

    public function testAnExpiredInvitationCannotBeUsed(): void
    {
        $owner = UserFactory::createOne();
        $comic = ComicFactory::new()->ownedBy($owner)->create();
        $recipient = $this->createAndLoginUser(['email' => 'late@test.local']);

        $share = new ComicShare($comic, $owner, $recipient->getEmail());
        $share->markPending(new \DateTimeImmutable('-1 day'));
        [$plaintext, $hash] = ShareInvitationToken::generate();
        $token = new ShareInvitationToken($share, $hash, new \DateTimeImmutable('-1 day'));

        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($share);
        $entityManager->persist($token);
        $entityManager->flush();

        $this->getJson('/api/shares/invitations/' . $plaintext);
        self::assertResponseStatusCodeSame(410);

        $this->postJson('/api/shares/invitations/' . $plaintext . '/accept');
        self::assertResponseStatusCodeSame(410);
    }

    public function testRecipientEmailsAreNormalisedOnTheWayIn(): void
    {
        $owner = $this->createAndLoginUser(['email' => 'owner@test.local']);
        $comic = ComicFactory::new()->ownedBy($owner)->create();

        $this->postInvitation((int) $comic->getId(), '  Mixed.Case@Example.COM  ');
        self::assertResponseStatusCodeSame(201);

        $shares = static::getContainer()->get(ComicShareRepository::class)->findBy(['owner' => $owner]);
        self::assertCount(1, $shares);
        self::assertSame('mixed.case@example.com', $shares[0]->getRecipientEmailNormalized());

        // The same address in a different spelling is the same recipient, so it
        // cannot open a second invitation.
        $repeat = $this->postInvitation((int) $comic->getId(), 'MIXED.CASE@example.com');
        self::assertSame(0, $repeat['created']);
        self::assertStringContainsString('already pending', $repeat['results'][0]['message']);
    }

    public function testAComicCannotBeSharedWithItsOwner(): void
    {
        $owner = $this->createAndLoginUser(['email' => 'self@test.local']);
        $comic = ComicFactory::new()->ownedBy($owner)->create();

        $payload = $this->postInvitation((int) $comic->getId(), 'SELF@test.local');

        self::assertResponseStatusCodeSame(400);
        self::assertSame('You cannot share a comic with yourself.', $payload['message']);
    }

    public function testOnlyTheOwnerCanManageAShare(): void
    {
        $owner = UserFactory::createOne();
        $comic = ComicFactory::new()->ownedBy($owner)->create();
        $recipient = UserFactory::createOne(['email' => 'managed@test.local']);

        $this->loginAs($recipient);
        $share = $this->createAcceptedShare($comic, $owner, $recipient);

        // The recipient is a party to this share, but managing it is not theirs:
        // reported as missing so share ids cannot be probed.
        $this->postJson('/api/shares/' . $share->getId() . '/revoke');
        self::assertResponseStatusCodeSame(404);
        $this->postJson('/api/shares/' . $share->getId() . '/resend');
        self::assertResponseStatusCodeSame(404);
        $this->deleteJson('/api/shares/' . $share->getId());
        self::assertResponseStatusCodeSame(404);

        $this->createAndLoginUser(['email' => 'outsider@test.local']);
        $this->postJson('/api/shares/' . $share->getId() . '/remove');
        self::assertResponseStatusCodeSame(404);
        $this->deleteJson('/api/shares/' . $share->getId());
        self::assertResponseStatusCodeSame(404);
    }

    public function testTheOwnerSeesEveryRecipientAndTheirState(): void
    {
        $owner = $this->createAndLoginUser(['email' => 'sharer@test.local']);
        $comic = ComicFactory::new()->ownedBy($owner)->create(['title' => 'Batman: Year One']);

        foreach (['jane@example.com', 'bob@example.com'] as $email) {
            $this->postInvitation((int) $comic->getId(), $email);
            self::assertResponseStatusCodeSame(201);
        }

        $shares = $this->getJson('/api/shares/shared-by-me')['sharedByMe'];
        self::assertCount(2, $shares);
        self::assertSame(['Batman: Year One'], array_values(array_unique(array_column($shares, 'comicTitle'))));
        self::assertEqualsCanonicalizing(
            ['jane@example.com', 'bob@example.com'],
            array_column($shares, 'recipientEmail')
        );
        self::assertSame(
            [ComicShare::STATUS_PENDING, ComicShare::STATUS_PENDING],
            array_column($shares, 'status')
        );
    }

    public function testAnOwnerCanDeleteTheRecordOfARevokedShare(): void
    {
        $owner = UserFactory::createOne();
        $comic = ComicFactory::new()->ownedBy($owner)->create();
        $recipient = UserFactory::createOne(['email' => 'cut-off@test.local']);

        $this->loginAs($recipient);
        $share = $this->createAcceptedShare($comic, $owner, $recipient);

        $this->loginAs($owner);
        $this->postJson('/api/shares/' . $share->getId() . '/revoke');
        self::assertResponseIsSuccessful();

        // The revoked row offers deletion, not another revocation.
        $shareRow = $this->getJson('/api/shares/shared-by-me')['sharedByMe'][0];
        self::assertTrue($shareRow['canDelete']);
        self::assertFalse($shareRow['canRevoke']);

        $this->deleteJson('/api/shares/' . $share->getId());
        self::assertResponseIsSuccessful();
        self::assertSame([], $this->getJson('/api/shares/shared-by-me')['sharedByMe']);

        // The id is read before the row goes, because a flushed deletion has
        // none left to name and an unnamed deletion is not answerable for.
        $record = $this->assertLoggedAuditEvent(SecurityAuditLogger::SHARES_CLEARED);
        self::assertSame((int) $share->getId(), $record->context['target_id']);
        self::assertSame((int) $owner->getId(), $record->context['actor_user_id']);
        self::assertSame('owner', $record->context['scope']);

        // The record is one row with two readers, so it leaves both lists.
        $this->loginAs($recipient);
        self::assertSame([], $this->getJson('/api/shares/shared-with-me')['sharedWithMe']);
    }

    /**
     * Deleting must never be a quieter way of cutting somebody off: a share
     * that still grants or promises access is refused until it is revoked,
     * declined or has lapsed.
     */
    public function testALiveShareMustBeRevokedBeforeItsRecordCanBeDeleted(): void
    {
        $owner = UserFactory::createOne();
        $comic = ComicFactory::new()->ownedBy($owner)->create();
        $recipient = UserFactory::createOne(['email' => 'keeps-access@test.local']);

        $this->loginAs($recipient);
        $accepted = $this->createAcceptedShare($comic, $owner, $recipient);

        $other = ComicFactory::new()->ownedBy($owner)->create();
        $pending = $this->persistShare($other, $owner, 'unanswered@test.local');

        $this->loginAs($owner);
        foreach ([$accepted, $pending] as $share) {
            $this->deleteJson('/api/shares/' . $share->getId());
            self::assertResponseStatusCodeSame(409);
        }

        // The refusals deleted nothing: the recipient still reads the comic.
        $this->loginAs($recipient);
        $this->getJson('/api/comics/' . $comic->getId());
        self::assertResponseIsSuccessful();

        // Once the invitation lapses there is nothing left to protect.
        $this->expireShareInvitation((int) $pending->getId());
        $this->loginAs($owner);
        $this->deleteJson('/api/shares/' . $pending->getId());
        self::assertResponseIsSuccessful();
    }

    public function testTheSharingListIsPagedByIndividualGrant(): void
    {
        $owner = $this->createAndLoginUser(['email' => 'prolific@test.local']);

        $comics = [];
        foreach (['Oldest', 'Middle', 'Newest'] as $title) {
            $comics[$title] = ComicFactory::new()->ownedBy($owner)->create(['title' => $title]);
        }
        $this->persistShare($comics['Oldest'], $owner, 'a@test.local');
        $this->persistShare($comics['Middle'], $owner, 'b@test.local');
        $this->persistShare($comics['Middle'], $owner, 'c@test.local');
        $this->persistShare($comics['Newest'], $owner, 'd@test.local');

        $first = $this->getJson('/api/shares/shared-by-me?page=1&limit=2');
        self::assertSame(
            ['page' => 1, 'limit' => 2, 'totalItems' => 4, 'totalPages' => 2],
            $first['pagination']
        );
        self::assertSame(['Newest', 'Middle'], array_column($first['sharedByMe'], 'comicTitle'));

        $second = $this->getJson('/api/shares/shared-by-me?page=2&limit=2');
        self::assertSame(['Middle', 'Oldest'], array_column($second['sharedByMe'], 'comicTitle'));

        // Beyond the end is an empty page, not an error.
        self::assertSame([], $this->getJson('/api/shares/shared-by-me?page=9&limit=2')['sharedByMe']);
    }

    public function testTheOwnerSharingTablePagesSortsSearchesAndFiltersIndividualShares(): void
    {
        $owner = $this->createAndLoginUser(['email' => 'table-owner@test.local']);
        $alpha = ComicFactory::new()->ownedBy($owner)->create(['title' => 'Alpha Book']);
        $zeta = ComicFactory::new()->ownedBy($owner)->create(['title' => 'Zeta Book']);

        $this->persistShare($alpha, $owner, 'charlie@test.local');
        $this->persistShare($alpha, $owner, 'alice@test.local');
        $this->persistShare($zeta, $owner, 'bob@test.local');

        $firstPage = $this->getJson('/api/shares/shared-by-me?page=1&limit=2&sort=comicTitle&direction=ASC');
        self::assertSame(
            ['page' => 1, 'limit' => 2, 'totalItems' => 3, 'totalPages' => 2],
            $firstPage['pagination']
        );
        self::assertSame(['Alpha Book', 'Alpha Book'], array_column($firstPage['sharedByMe'], 'comicTitle'));
        self::assertSame(
            ['alice@test.local', 'charlie@test.local'],
            array_column($firstPage['sharedByMe'], 'recipientEmail')
        );

        $search = $this->getJson('/api/shares/shared-by-me?search=bob%40test.local');
        self::assertSame(['Zeta Book'], array_column($search['sharedByMe'], 'comicTitle'));

        $filtered = $this->getJson(
            '/api/shares/shared-by-me?filterComic=alpha&filterRecipient=charlie&filterStatus=Pending'
        );
        self::assertSame(['charlie@test.local'], array_column($filtered['sharedByMe'], 'recipientEmail'));
    }

    public function testTheOwnerSharingTableFindsARecipientByTheDisplayedUserCode(): void
    {
        $owner = $this->createAndLoginUser(['email' => 'code-filter-owner@test.local']);
        $comic = ComicFactory::new()->ownedBy($owner)->create(['title' => 'Private Route']);
        $visibleComic = ComicFactory::new()->ownedBy($owner)->create(['title' => 'Known Address Route']);
        $recipient = UserFactory::createOne(['email' => 'hidden-recipient@test.local']);
        $recipient->replaceUserCode('23DFTC956NTS');

        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $entityManager->flush();

        $share = $this->persistShare($comic, $owner, (string) $recipient->getEmail());
        $share
            ->hideRecipientBehindSharingCode('23DFTC956NTS', $recipient->getName())
            ->linkRecipientUser($this->managed(User::class, (int) $recipient->getId()));
        $entityManager->flush();

        // The same account also has an ordinary email share. Matching its
        // current code must not reveal that this otherwise address-only row is
        // connected to the code.
        $visibleShare = $this->persistShare($visibleComic, $owner, (string) $recipient->getEmail());
        $visibleShare->linkRecipientUser($this->managed(User::class, (int) $recipient->getId()));
        $entityManager->flush();

        foreach (['filterRecipient', 'search'] as $parameter) {
            $payload = $this->getJson(sprintf(
                '/api/shares/shared-by-me?%s=U-23DF-TC95-6NTS',
                $parameter
            ));

            self::assertSame([$share->getId()], array_column($payload['sharedByMe'], 'id'), $parameter);
            self::assertSame('U-23DF-TC95-6NTS', $payload['sharedByMe'][0]['recipientUserCode']);
            self::assertNull($payload['sharedByMe'][0]['recipientEmail']);
        }
    }

    public function testTheRecipientSharingTablePagesSortsSearchesAndFiltersIndividualShares(): void
    {
        $recipient = $this->createAndLoginUser(['email' => 'table-recipient@test.local']);
        $alex = UserFactory::createOne(['email' => 'alex-owner@test.local', 'name' => 'Alex Owner']);
        $blair = UserFactory::createOne(['email' => 'blair-owner@test.local', 'name' => 'Blair Owner']);
        $alpha = ComicFactory::new()->ownedBy($alex)->create(['title' => 'Alpha Book']);
        $middle = ComicFactory::new()->ownedBy($blair)->create(['title' => 'Middle Book']);
        $zeta = ComicFactory::new()->ownedBy($alex)->create(['title' => 'Zeta Book']);

        $this->persistShare($zeta, $alex, (string) $recipient->getEmail());
        $this->persistShare($middle, $blair, (string) $recipient->getEmail());
        $this->createAcceptedShare($alpha, $alex, $recipient);

        $firstPage = $this->getJson('/api/shares/shared-with-me?page=1&limit=2&sort=comicTitle&direction=ASC');
        self::assertSame(
            ['page' => 1, 'limit' => 2, 'totalItems' => 3, 'totalPages' => 2],
            $firstPage['pagination']
        );
        self::assertSame(['Alpha Book', 'Middle Book'], array_column($firstPage['sharedWithMe'], 'comicTitle'));

        $search = $this->getJson('/api/shares/shared-with-me?search=blair');
        self::assertSame(['Middle Book'], array_column($search['sharedWithMe'], 'comicTitle'));

        $filtered = $this->getJson(
            '/api/shares/shared-with-me?filterComic=zeta&filterOwner=alex&filterStatus=Pending'
        );
        self::assertSame(['Zeta Book'], array_column($filtered['sharedWithMe'], 'comicTitle'));
    }

    /**
     * The retention sweep removes a revoked share only once its window has
     * passed, and never touches anything still live — pressing the admin
     * button early must not delete what the nightly job would have kept.
     */
    public function testTheRetentionSweepDeletesOnlyLongRevokedShares(): void
    {
        $owner = UserFactory::createOne();
        $comic = ComicFactory::new()->ownedBy($owner)->create();

        $longRevoked = $this->persistShare($comic, $owner, 'long-revoked@test.local');
        $freshlyRevoked = $this->persistShare($comic, $owner, 'freshly-revoked@test.local');
        $this->persistShare($comic, $owner, 'still-pending@test.local');

        $shareService = static::getContainer()->get(ComicShareService::class);
        $shareService->revoke($longRevoked);
        $shareService->revoke($freshlyRevoked);

        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $entityManager->getConnection()->executeStatement(
            'UPDATE comic_share SET revoked_at = :when WHERE id = :id',
            [
                'when' => (new \DateTimeImmutable('-40 days'))->format('Y-m-d H:i:s'),
                'id' => $longRevoked->getId(),
            ]
        );
        $entityManager->clear();

        $cleanup = static::getContainer()->get(ExpiredShareCleanupService::class);
        self::assertSame(1, $cleanup->cleanupRevokedShares());
        // Nothing left in the window: a second pass finds the same table clean.
        self::assertSame(0, $cleanup->cleanupRevokedShares());

        $left = static::getContainer()->get(ComicShareRepository::class)
            ->findBy(['comic' => $comic->getId()]);
        self::assertEqualsCanonicalizing(
            ['freshly-revoked@test.local', 'still-pending@test.local'],
            array_map(static fn (ComicShare $share): string => $share->getRecipientEmailNormalized(), $left)
        );
    }

    /**
     * The link exists in one place: the email. It is minted as that message is
     * written, so nothing is stored that could reproduce it and no response
     * ever carried it.
     */
    public function testTheInvitationLinkOnlyEverExistsInTheEmail(): void
    {
        $owner = $this->createAndLoginUser(['email' => 'linker@test.local']);
        $comic = ComicFactory::new()->ownedBy($owner)->create();

        $payload = $this->postInvitation((int) $comic->getId(), 'guest@example.com');
        self::assertResponseStatusCodeSame(201);
        self::assertArrayNotHasKey('invitationUrl', $payload);

        $plaintext = $this->invitationTokenFromEmail();
        self::assertNotSame('', $plaintext);

        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $tokens = $entityManager->getRepository(ShareInvitationToken::class)->findAll();
        self::assertCount(1, $tokens);
        self::assertNotSame($plaintext, $tokens[0]->getTokenHash());
        self::assertSame(ShareInvitationToken::hash($plaintext), $tokens[0]->getTokenHash());
    }

    public function testAnInvitationLinkIsGoodForOneClaimWithinTwoMonths(): void
    {
        $owner = $this->createAndLoginUser(['email' => 'lifecycle@test.local']);
        $comic = ComicFactory::new()->ownedBy($owner)->create();

        $payload = $this->postInvitation((int) $comic->getId(), 'claimant@test.local');
        self::assertResponseStatusCodeSame(201);
        $plaintext = $this->invitationTokenFromEmail();

        // The window is two months, and the link and the relationship share it,
        // so neither can outlive the other.
        $share = $this->getJson('/api/shares/shared-by-me')['sharedByMe'][0];
        $expiresAt = new \DateTimeImmutable($share['expiresAt']);
        self::assertGreaterThan(new \DateTimeImmutable('+59 days'), $expiresAt);
        self::assertLessThan(new \DateTimeImmutable('+62 days'), $expiresAt);

        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $tokens = $entityManager->getRepository(ShareInvitationToken::class)->findAll();
        self::assertCount(1, $tokens);
        self::assertEqualsWithDelta(
            $expiresAt->getTimestamp(),
            $tokens[0]->getExpiresAt()->getTimestamp(),
            5,
            'The link and the invitation must run out together.'
        );

        // Previewing does not spend it, however many times it is followed.
        // Mail scanners and link-preview services open links on the recipient's
        // behalf, so a link that burned on a GET would be dead before the person
        // it was sent to ever saw it.
        $this->createAndLoginUser(['email' => 'claimant@test.local']);
        foreach ([1, 2, 3] as $_) {
            $this->getJson('/api/shares/invitations/' . $plaintext);
            self::assertResponseIsSuccessful();
        }

        $this->postJson('/api/shares/invitations/' . $plaintext . '/accept');
        self::assertResponseIsSuccessful();

        // Claimed, and therefore spent — for every one of the things a link can
        // be used for, not merely the one that spent it.
        $entityManager->clear();
        $spent = $entityManager->getRepository(ShareInvitationToken::class)->findAll();
        self::assertCount(1, $spent);
        self::assertNotNull($spent[0]->getUsedAt());
        self::assertFalse($spent[0]->isUsable());

        $this->getJson('/api/shares/invitations/' . $plaintext);
        self::assertResponseStatusCodeSame(409);
        $this->postJson('/api/shares/invitations/' . $plaintext . '/accept');
        self::assertResponseStatusCodeSame(409);
        $this->postJson('/api/shares/invitations/' . $plaintext . '/decline');
        self::assertResponseStatusCodeSame(409);
    }

    public function testResendingInvalidatesThePreviousLink(): void
    {
        $owner = $this->createAndLoginUser(['email' => 'resender@test.local']);
        $comic = ComicFactory::new()->ownedBy($owner)->create();

        $this->postInvitation((int) $comic->getId(), 'guest@example.com');
        // Read before the next request: the mailer collector holds what the
        // most recent one sent, so a lookup in between would clear it.
        $firstToken = $this->invitationTokenFromEmail();
        $shareId = $this->getJson('/api/shares/shared-by-me')['sharedByMe'][0]['id'];

        // Resending is the manual counterpart to the queued notice and is
        // synchronous, so the replacement email is on the collector as soon as
        // the request returns. The link is read from there and not from the
        // response: it belongs in one place, and resend is not an exception to
        // that.
        $second = $this->postJson('/api/shares/' . $shareId . '/resend');
        self::assertResponseIsSuccessful();
        self::assertArrayNotHasKey('invitationUrl', $second);

        $secondToken = $this->invitationTokenFromEmail();
        self::assertNotSame($firstToken, $secondToken);

        $this->getJson('/api/shares/invitations/' . $firstToken);
        self::assertResponseStatusCodeSame(410);

        $this->getJson('/api/shares/invitations/' . $secondToken);
        self::assertResponseIsSuccessful();
    }

    public function testInvitationsAreRateLimitedPerOwner(): void
    {
        $owner = $this->createAndLoginUser(['email' => 'prolific@test.local']);
        $comic = ComicFactory::new()->ownedBy($owner)->create();

        for ($i = 0; $i < 10; ++$i) {
            $this->postInvitation((int) $comic->getId(), sprintf('guest%d@example.com', $i));
            self::assertResponseStatusCodeSame(201, sprintf('Invitation %d should be within the allowance.', $i + 1));
        }

        $payload = $this->postInvitation((int) $comic->getId(), 'one-too-many@example.com');

        self::assertResponseStatusCodeSame(429);
        self::assertStringContainsString('too many invitations', $payload['message']);
    }

    public function testRejectedInvitationsDoNotSpendTheAllowance(): void
    {
        $owner = $this->createAndLoginUser(['email' => 'careful-sharer@test.local']);
        $comic = ComicFactory::new()->ownedBy($owner)->create();

        // Requests turned away before anything is sent — a duplicate, and an
        // attempt to share with yourself — must not count against the limit.
        $this->postInvitation((int) $comic->getId(), 'guest@example.com');
        self::assertResponseStatusCodeSame(201);

        for ($i = 0; $i < 5; ++$i) {
            $duplicate = $this->postInvitation((int) $comic->getId(), 'guest@example.com');
            self::assertSame(0, $duplicate['created']);
            $this->postInvitation((int) $comic->getId(), 'careful-sharer@test.local');
            self::assertResponseStatusCodeSame(400);
        }

        // Nine of the ten remain, so this is still accepted.
        $this->postInvitation((int) $comic->getId(), 'another@example.com');
        self::assertResponseStatusCodeSame(201);
    }

    public function testSummaryCountsPendingInvitationsForTheNavigationBadge(): void
    {
        $owner = UserFactory::createOne();
        $comic = ComicFactory::new()->ownedBy($owner)->create();
        $recipient = $this->createAndLoginUser(['email' => 'badge@test.local']);

        self::assertSame(0, $this->getJson('/api/shares/summary')['pendingInvitations']);

        $this->persistShare($comic, $owner, (string) $recipient->getEmail());

        self::assertSame(1, $this->getJson('/api/shares/summary')['pendingInvitations']);
    }

    /**
     * Accept a share the way the recipient would, through the service, so the
     * tests exercise real state transitions rather than hand-built rows.
     *
     * The caller must already be logged in as the recipient where the assertions
     * depend on it; this only manipulates data.
     */
    private function createAcceptedShare(Comic $comic, User $owner, User $recipient): ComicShare
    {
        $share = $this->persistShare($comic, $owner, (string) $recipient->getEmail());

        static::getContainer()->get(ComicShareService::class)
            ->acceptShare($share, $this->managed(User::class, (int) $recipient->getId()));

        return $share;
    }

    /**
     * Send an invitation the way the share modal does, acknowledgement and all.
     *
     * Every share now carries the sender's acknowledgement, so the tests that
     * are about something else say so once here rather than repeating the tick
     * box in each of them.
     *
     * @param array<string, mixed> $extra additional or overriding body fields
     * @return array<string, mixed>
     */
    private function postInvitation(int $comicId, string $email, array $extra = []): array
    {
        return $this->postJson(
            '/api/shares/invitations/bulk',
            array_merge(['comicIds' => [$comicId], 'email' => $email, 'senderResponsibilityAccepted' => true], $extra)
        );
    }

    /**
     * A pending invitation plus the plaintext token for its link.
     *
     * @return array{0: ComicShare, 1: string}
     */
    private function createPendingInvitation(Comic $comic, User $owner, string $recipientEmail): array
    {
        $share = $this->persistShare($comic, $owner, $recipientEmail);

        [$plaintext, $hash] = ShareInvitationToken::generate();
        $token = new ShareInvitationToken($share, $hash, new \DateTimeImmutable(ComicShareService::INVITATION_TTL));

        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($token);
        $entityManager->flush();

        return [$share, $plaintext];
    }

    /** Push a pending invitation's window into the past, straight in the database. */
    private function expireShareInvitation(int $shareId): void
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $entityManager->getConnection()->executeStatement(
            'UPDATE comic_share SET expires_at = :when WHERE id = :id',
            ['when' => (new \DateTimeImmutable('-1 day'))->format('Y-m-d H:i:s'), 'id' => $shareId]
        );
        $entityManager->clear();
    }

    private function persistShare(Comic $comic, User $owner, string $recipientEmail): ComicShare
    {
        $share = new ComicShare(
            $this->managed(Comic::class, (int) $comic->getId()),
            $this->managed(User::class, (int) $owner->getId()),
            $recipientEmail
        );
        $share->markPending(new \DateTimeImmutable(ComicShareService::INVITATION_TTL));

        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($share);
        $entityManager->flush();

        return $share;
    }

    /**
     * Look an entity up again in the entity manager the container is handing
     * out now.
     *
     * Every request in these tests ends with a fresh entity manager, so a
     * factory object created before one is detached afterwards — and attaching
     * it to a new ComicShare would make Doctrine treat an already-stored comic
     * as a brand new one.
     *
     * @template T of object
     * @param class-string<T> $class
     * @return T
     */
    private function managed(string $class, int $id): object
    {
        $entity = static::getContainer()->get(EntityManagerInterface::class)->find($class, $id);
        self::assertNotNull($entity, sprintf('Expected a stored %s with id %d.', $class, $id));

        return $entity;
    }
}
