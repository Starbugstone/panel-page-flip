<?php

namespace App\Tests\Functional\Controller;

use App\Entity\Comic;
use App\Entity\ComicShare;
use App\Entity\ShareInvitationToken;
use App\Entity\User;
use App\Repository\ComicShareRepository;
use App\Service\ComicShareService;
use App\Tests\Factory\ComicFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Functional\AbstractApiTestCase;
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
    public function testAcceptingAShareCreatesNoSecondComicOrFile(): void
    {
        $owner = UserFactory::createOne()->object();
        $comic = ComicFactory::new()->ownedBy($owner)->create()->object();
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
        $owner = UserFactory::createOne()->object();
        $comic = ComicFactory::new()->ownedBy($owner)->create([
            'coverImagePath' => 'covers/missing/cover.png',
        ])->object();
        $recipient = $this->createAndLoginUser(['email' => 'not-yet@test.local']);

        // A pending invitation exists, and grants nothing.
        $this->persistShare($comic, $owner, (string) $recipient->getEmail());

        $this->getJson('/api/comics/' . $comic->getId());
        self::assertResponseStatusCodeSame(403);

        // Both the denial and a missing archive answer 404, so the message is
        // what distinguishes "you may not read this" from "there is nothing to
        // read" — and only the first is being asserted here.
        $this->browser()->request('GET', '/api/comics/' . $comic->getId() . '/pages/1');
        self::assertResponseStatusCodeSame(404);
        self::assertSame('Comic not found', $this->json()['message']);

        $this->browser()->request(
            'GET',
            sprintf('/api/comics/cover/%d/%d/cover.png', $owner->getId(), $comic->getId())
        );
        self::assertResponseStatusCodeSame(403);
    }

    public function testAnAcceptedShareUnlocksMetadataAndTheCover(): void
    {
        $owner = UserFactory::createOne()->object();
        $comic = ComicFactory::new()->ownedBy($owner)->create([
            'coverImagePath' => 'covers/missing/cover.png',
        ])->object();
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
        $owner = UserFactory::createOne()->object();
        $comic = ComicFactory::new()->ownedBy($owner)->create(['title' => 'Batman: Year One'])->object();
        $recipient = $this->createAndLoginUser(['email' => 'collector@test.local']);
        $ownComic = ComicFactory::new()->ownedBy($recipient)->create()->object();
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
        $owner = UserFactory::createOne()->object();
        $comic = ComicFactory::new()->ownedBy($owner)->create(['title' => 'Untouched'])->object();
        $recipient = $this->createAndLoginUser(['email' => 'nosy@test.local']);
        $this->createAcceptedShare($comic, $owner, $recipient);

        $this->patchJson('/api/comics/' . $comic->getId(), ['title' => 'Hijacked']);
        self::assertResponseStatusCodeSame(403);

        $this->browser()->request('DELETE', '/api/comics/' . $comic->getId(), [], [], $this->csrfHeader());
        self::assertResponseStatusCodeSame(403);

        $this->postJson('/api/shares/comics/' . $comic->getId() . '/invitations', ['email' => 'third@test.local']);
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
        $owner = UserFactory::createOne()->object();
        $comic = ComicFactory::new()->ownedBy($owner)->create(['pageCount' => 40])->object();
        $recipient = UserFactory::createOne(['email' => 'independent@test.local'])->object();

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
        $owner = UserFactory::createOne()->object();
        $comic = ComicFactory::new()->ownedBy($owner)->create()->object();
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
        $owner = UserFactory::createOne()->object();
        $comic = ComicFactory::new()->ownedBy($owner)->create()->object();
        $recipient = UserFactory::createOne(['email' => 'revoked@test.local'])->object();

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
        $this->browser()->request('GET', '/api/comics/' . $comic->getId() . '/pages/1');
        self::assertResponseStatusCodeSame(404);
        self::assertSame('Comic not found', $this->json()['message']);
        self::assertSame([], $this->getJson('/api/comics')['comics']);
    }

    public function testStoppingSharingRevokesEveryRecipientAtOnce(): void
    {
        $owner = UserFactory::createOne()->object();
        $comic = ComicFactory::new()->ownedBy($owner)->create()->object();
        $first = UserFactory::createOne(['email' => 'first@test.local'])->object();
        $second = UserFactory::createOne(['email' => 'second@test.local'])->object();

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

    public function testDeletingTheOriginalLeavesRecipientsATombstone(): void
    {
        $owner = UserFactory::createOne()->object();
        $comic = ComicFactory::new()->ownedBy($owner)->create(['title' => 'Doomed'])->object();
        $comicId = $comic->getId();
        $recipient = UserFactory::createOne(['email' => 'bereaved@test.local'])->object();

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

    public function testOwnersAreToldHowManyPeopleADeletionWouldCutOff(): void
    {
        $owner = UserFactory::createOne()->object();
        $comic = ComicFactory::new()->ownedBy($owner)->create()->object();
        $recipient = UserFactory::createOne(['email' => 'counted@test.local'])->object();

        $this->loginAs($recipient);
        $this->createAcceptedShare($comic, $owner, $recipient);

        $this->loginAs($owner);
        self::assertSame(1, $this->getJson('/api/comics/' . $comic->getId())['comic']['sharedWithCount']);
    }

    public function testTombstonesCanBeClearedIndividuallyAndInBulkWithoutTouchingLiveShares(): void
    {
        $owner = UserFactory::createOne()->object();
        $doomedFirst = ComicFactory::new()->ownedBy($owner)->create()->object();
        $doomedSecond = ComicFactory::new()->ownedBy($owner)->create()->object();
        $survivor = ComicFactory::new()->ownedBy($owner)->create()->object();
        $recipient = UserFactory::createOne(['email' => 'sweeper@test.local'])->object();

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
        $owner = UserFactory::createOne()->object();
        $comic = ComicFactory::new()->ownedBy($owner)->create()->object();
        $victim = UserFactory::createOne(['email' => 'victim@test.local'])->object();

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
        $owner = UserFactory::createOne()->object();
        $comic = ComicFactory::new()->ownedBy($owner)->create(['title' => 'Peeked At'])->object();
        $recipient = UserFactory::createOne(['email' => 'careful@test.local'])->object();
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
        $owner = UserFactory::createOne()->object();
        $comic = ComicFactory::new()->ownedBy($owner)->create()->object();
        [, $plaintext] = $this->createPendingInvitation($comic, $owner, 'intended@test.local');

        $this->createAndLoginUser(['email' => 'interloper@test.local']);
        $this->postJson('/api/shares/invitations/' . $plaintext . '/accept');
        self::assertResponseStatusCodeSame(403);

        $this->getJson('/api/comics/' . $comic->getId());
        self::assertResponseStatusCodeSame(403);
    }

    public function testAcceptingAnInvitationSpendsItsToken(): void
    {
        $owner = UserFactory::createOne()->object();
        $comic = ComicFactory::new()->ownedBy($owner)->create()->object();
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
        $owner = UserFactory::createOne()->object();
        $comic = ComicFactory::new()->ownedBy($owner)->create()->object();
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
        $owner = UserFactory::createOne()->object();
        $comic = ComicFactory::new()->ownedBy($owner)->create()->object();
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
        $comic = ComicFactory::new()->ownedBy($owner)->create()->object();

        $this->postJson(
            '/api/shares/comics/' . $comic->getId() . '/invitations',
            ['email' => '  Mixed.Case@Example.COM  ']
        );
        self::assertResponseStatusCodeSame(201);

        $shares = static::getContainer()->get(ComicShareRepository::class)->findAllForOwner($owner);
        self::assertCount(1, $shares);
        self::assertSame('mixed.case@example.com', $shares[0]->getRecipientEmailNormalized());

        // The same address in a different spelling is the same recipient, so it
        // cannot open a second invitation.
        $this->postJson(
            '/api/shares/comics/' . $comic->getId() . '/invitations',
            ['email' => 'MIXED.CASE@example.com']
        );
        self::assertResponseStatusCodeSame(409);
    }

    public function testAComicCannotBeSharedWithItsOwner(): void
    {
        $owner = $this->createAndLoginUser(['email' => 'self@test.local']);
        $comic = ComicFactory::new()->ownedBy($owner)->create()->object();

        $payload = $this->postJson(
            '/api/shares/comics/' . $comic->getId() . '/invitations',
            ['email' => 'SELF@test.local']
        );

        self::assertResponseStatusCodeSame(400);
        self::assertStringContainsString('already own', $payload['message']);
    }

    public function testOnlyTheOwnerCanManageAShare(): void
    {
        $owner = UserFactory::createOne()->object();
        $comic = ComicFactory::new()->ownedBy($owner)->create()->object();
        $recipient = UserFactory::createOne(['email' => 'managed@test.local'])->object();

        $this->loginAs($recipient);
        $share = $this->createAcceptedShare($comic, $owner, $recipient);

        // The recipient is a party to this share, but managing it is not theirs:
        // reported as missing so share ids cannot be probed.
        $this->postJson('/api/shares/' . $share->getId() . '/revoke');
        self::assertResponseStatusCodeSame(404);
        $this->postJson('/api/shares/' . $share->getId() . '/resend');
        self::assertResponseStatusCodeSame(404);

        $this->createAndLoginUser(['email' => 'outsider@test.local']);
        $this->postJson('/api/shares/' . $share->getId() . '/remove');
        self::assertResponseStatusCodeSame(404);
    }

    public function testTheOwnerSeesEveryRecipientAndTheirState(): void
    {
        $owner = $this->createAndLoginUser(['email' => 'sharer@test.local']);
        $comic = ComicFactory::new()->ownedBy($owner)->create(['title' => 'Batman: Year One'])->object();

        foreach (['jane@example.com', 'bob@example.com'] as $email) {
            $this->postJson('/api/shares/comics/' . $comic->getId() . '/invitations', ['email' => $email]);
            self::assertResponseStatusCodeSame(201);
        }

        $groups = $this->getJson('/api/shares/shared-by-me')['sharedByMe'];
        self::assertCount(1, $groups);
        self::assertSame('Batman: Year One', $groups[0]['title']);
        self::assertCount(2, $groups[0]['recipients']);
        self::assertEqualsCanonicalizing(
            ['jane@example.com', 'bob@example.com'],
            array_column($groups[0]['recipients'], 'recipientEmail')
        );
        self::assertSame(
            [ComicShare::STATUS_PENDING, ComicShare::STATUS_PENDING],
            array_column($groups[0]['recipients'], 'status')
        );
    }

    public function testTheInvitationLinkIsHandedBackOnceAndNeverStoredInPlaintext(): void
    {
        $owner = $this->createAndLoginUser(['email' => 'linker@test.local']);
        $comic = ComicFactory::new()->ownedBy($owner)->create()->object();

        $payload = $this->postJson(
            '/api/shares/comics/' . $comic->getId() . '/invitations',
            ['email' => 'guest@example.com']
        );
        self::assertResponseStatusCodeSame(201);
        self::assertArrayHasKey('invitationUrl', $payload);

        $plaintext = substr((string) strrchr($payload['invitationUrl'], '/'), 1);
        self::assertNotSame('', $plaintext);

        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $tokens = $entityManager->getRepository(ShareInvitationToken::class)->findAll();
        self::assertCount(1, $tokens);
        self::assertNotSame($plaintext, $tokens[0]->getTokenHash());
        self::assertSame(ShareInvitationToken::hash($plaintext), $tokens[0]->getTokenHash());
    }

    public function testResendingInvalidatesThePreviousLink(): void
    {
        $owner = $this->createAndLoginUser(['email' => 'resender@test.local']);
        $comic = ComicFactory::new()->ownedBy($owner)->create()->object();

        $first = $this->postJson(
            '/api/shares/comics/' . $comic->getId() . '/invitations',
            ['email' => 'guest@example.com']
        );
        $shareId = $first['share']['id'];
        $firstToken = substr((string) strrchr($first['invitationUrl'], '/'), 1);

        $second = $this->postJson('/api/shares/' . $shareId . '/resend');
        self::assertResponseIsSuccessful();
        $secondToken = substr((string) strrchr($second['invitationUrl'], '/'), 1);
        self::assertNotSame($firstToken, $secondToken);

        $this->getJson('/api/shares/invitations/' . $firstToken);
        self::assertResponseStatusCodeSame(410);

        $this->getJson('/api/shares/invitations/' . $secondToken);
        self::assertResponseIsSuccessful();
    }

    public function testSummaryCountsPendingInvitationsForTheNavigationBadge(): void
    {
        $owner = UserFactory::createOne()->object();
        $comic = ComicFactory::new()->ownedBy($owner)->create()->object();
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
     * A pending invitation plus the plaintext token for its link.
     *
     * @return array{0: ComicShare, 1: string}
     */
    private function createPendingInvitation(Comic $comic, User $owner, string $recipientEmail): array
    {
        $share = $this->persistShare($comic, $owner, $recipientEmail);

        [$plaintext, $hash] = ShareInvitationToken::generate();
        $token = new ShareInvitationToken($share, $hash, new \DateTimeImmutable('+7 days'));

        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($token);
        $entityManager->flush();

        return [$share, $plaintext];
    }

    private function persistShare(Comic $comic, User $owner, string $recipientEmail): ComicShare
    {
        $share = new ComicShare(
            $this->managed(Comic::class, (int) $comic->getId()),
            $this->managed(User::class, (int) $owner->getId()),
            $recipientEmail
        );
        $share->markPending(new \DateTimeImmutable('+7 days'));

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
