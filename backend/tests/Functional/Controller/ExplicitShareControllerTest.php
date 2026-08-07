<?php

namespace App\Tests\Functional\Controller;

use App\Entity\Comic;
use App\Entity\ComicShare;
use App\Entity\ShareInvitationToken;
use App\Entity\Tag;
use App\Entity\User;
use App\Service\ComicShareService;
use App\Service\ShareException;
use App\Tests\Factory\ComicFactory;
use App\Tests\Factory\TagFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Functional\AbstractApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Explicit-content classification, and the two acknowledgements a share records.
 *
 * The rules pinned here are the ones the React warning cannot be trusted with:
 * a share cannot exist without the sender's acknowledgement, an explicit comic
 * gives up nothing that identifies it until its recipient has declared their
 * age, and both timestamps belong to the server. The classification is the
 * owner's alone — a tag, hidden or not, never stands in for it.
 */
final class ExplicitShareControllerTest extends AbstractApiTestCase
{
    /* ---------------------------------------------------------------------- */
    /* Classification                                                          */
    /* ---------------------------------------------------------------------- */

    public function testAComicIsNotExplicitUntilItsOwnerSaysSo(): void
    {
        $owner = $this->createAndLoginUser(['email' => 'classifier@test.local']);
        $comic = ComicFactory::new()->ownedBy($owner)->create()->object();

        self::assertFalse($this->getJson('/api/comics/' . $comic->getId())['comic']['explicitContent']);

        $this->patchJson('/api/comics/' . $comic->getId(), ['explicitContent' => true]);
        self::assertResponseIsSuccessful();
        self::assertTrue($this->getJson('/api/comics/' . $comic->getId())['comic']['explicitContent']);

        // And back again, because unticking the box has to be a change too.
        $this->patchJson('/api/comics/' . $comic->getId(), ['explicitContent' => false]);
        self::assertResponseIsSuccessful();
        self::assertFalse($this->getJson('/api/comics/' . $comic->getId())['comic']['explicitContent']);
    }

    public function testHidingAComicFromTheLibraryIsNotAnAgeClassification(): void
    {
        $owner = $this->createAndLoginUser(['email' => 'shelver@test.local']);
        $comic = ComicFactory::new()->ownedBy($owner)->create()->object();

        $hidden = TagFactory::new()->createdBy($owner)->create(['name' => 'private-shelf'])->object();
        $this->managed(Tag::class, (int) $hidden->getId())->setHideFromLibrary(true);
        $this->flush();

        $this->patchJson('/api/comics/' . $comic->getId(), ['tags' => ['private-shelf']]);
        self::assertResponseIsSuccessful();

        // A hidden tag is a shelving preference. Somebody may hide a comic for
        // a dozen unrelated reasons, and none of them says anything about what
        // is inside it.
        self::assertFalse($this->getJson('/api/comics/' . $comic->getId())['comic']['explicitContent']);

        // So sharing it behaves exactly like sharing anything else: no gate.
        $recipient = UserFactory::createOne(['email' => 'unbothered@test.local'])->object();
        $this->postInvitation((int) $comic->getId(), (string) $recipient->getEmail());
        self::assertResponseStatusCodeSame(201);

        $this->loginAs($recipient);
        $received = $this->getJson('/api/shares/shared-with-me')['sharedWithMe'][0];
        self::assertFalse($received['requiresAdultConfirmation']);
        self::assertNotNull($received['comicTitle']);
    }

    public function testChangingTagsNeverTouchesTheExplicitFlag(): void
    {
        $owner = $this->createAndLoginUser(['email' => 'tagger@test.local']);
        $comic = ComicFactory::new()->ownedBy($owner)->explicit()->create()->object();

        $this->patchJson('/api/comics/' . $comic->getId(), ['tags' => ['horror', 'noir']]);
        self::assertResponseIsSuccessful();

        self::assertTrue($this->getJson('/api/comics/' . $comic->getId())['comic']['explicitContent']);
    }

    /* ---------------------------------------------------------------------- */
    /* Sender acknowledgement                                                  */
    /* ---------------------------------------------------------------------- */

    public function testAnInvitationWithoutTheSenderAcknowledgementIsRejected(): void
    {
        $owner = $this->createAndLoginUser(['email' => 'unacknowledged@test.local']);
        $comic = ComicFactory::new()->ownedBy($owner)->create()->object();

        foreach ([[], ['senderResponsibilityAccepted' => false], ['senderResponsibilityAccepted' => 'true']] as $body) {
            $payload = $this->postJson(
                '/api/shares/comics/' . $comic->getId() . '/invitations',
                array_merge(['email' => 'guest@example.com'], $body)
            );

            self::assertResponseStatusCodeSame(400);
            self::assertSame(ShareException::CODE_RESPONSIBILITY_REQUIRED, $payload['code']);
        }

        // And nothing was created on the way to any of those rejections.
        self::assertSame([], $this->getJson('/api/shares/shared-by-me')['sharedByMe']);
    }

    public function testAnAcknowledgedInvitationStoresAServerGeneratedTimestamp(): void
    {
        $owner = $this->createAndLoginUser(['email' => 'acknowledger@test.local']);
        $comic = ComicFactory::new()->ownedBy($owner)->create()->object();

        $before = new \DateTimeImmutable('-1 minute');
        $this->postInvitation((int) $comic->getId(), 'guest@example.com');
        self::assertResponseStatusCodeSame(201);

        $share = $this->onlyShare();
        self::assertNotNull($share->getSenderResponsibilityAcceptedAt());
        self::assertGreaterThan($before, $share->getSenderResponsibilityAcceptedAt());
        self::assertLessThan(new \DateTimeImmutable('+1 minute'), $share->getSenderResponsibilityAcceptedAt());
    }

    public function testAClientCannotSupplyItsOwnSenderAcknowledgementTimestamp(): void
    {
        $owner = $this->createAndLoginUser(['email' => 'forger@test.local']);
        $comic = ComicFactory::new()->ownedBy($owner)->create()->object();

        $this->postInvitation((int) $comic->getId(), 'guest@example.com', [
            'senderResponsibilityAcceptedAt' => '1999-01-01T00:00:00+00:00',
        ]);
        self::assertResponseStatusCodeSame(201);

        // An audit trail the audited party can write is not one.
        $accepted = $this->onlyShare()->getSenderResponsibilityAcceptedAt();
        self::assertNotNull($accepted);
        self::assertGreaterThan(new \DateTimeImmutable('-1 minute'), $accepted);
    }

    public function testTheAcknowledgementIsNotExposedAsATimestampToClients(): void
    {
        $owner = $this->createAndLoginUser(['email' => 'discreet@test.local']);
        $comic = ComicFactory::new()->ownedBy($owner)->create()->object();

        $payload = $this->postInvitation((int) $comic->getId(), 'guest@example.com');

        // The record is kept server-side; the client is told state, not history.
        self::assertArrayNotHasKey('senderResponsibilityAcceptedAt', $payload['share']);
        self::assertArrayNotHasKey('adultConfirmedAt', $payload['share']);
    }

    public function testResendingKeepsTheOriginalSenderAcknowledgement(): void
    {
        $owner = $this->createAndLoginUser(['email' => 'resender@test.local']);
        $comic = ComicFactory::new()->ownedBy($owner)->create()->object();

        $payload = $this->postInvitation((int) $comic->getId(), 'guest@example.com');
        $shareId = $payload['share']['id'];
        // Read back from storage on both sides. The in-memory object still
        // carries microseconds the DATETIME column does not, and comparing one
        // against the other would fail on precision rather than on the value.
        $this->refresh();
        $original = $this->onlyShare()->getSenderResponsibilityAcceptedAt();

        $this->postJson('/api/shares/' . $shareId . '/resend');
        self::assertResponseIsSuccessful();

        // Resending is the same relationship reaching the same person again, so
        // there is no second decision to record.
        $this->refresh();
        self::assertEquals($original, $this->onlyShare()->getSenderResponsibilityAcceptedAt());
    }

    /* ---------------------------------------------------------------------- */
    /* What an unconfirmed recipient may see                                   */
    /* ---------------------------------------------------------------------- */

    public function testAnExplicitPendingShareRevealsNothingThatIdentifiesTheComic(): void
    {
        $owner = UserFactory::createOne(['name' => 'Alex Owner'])->object();
        $comic = ComicFactory::new()->ownedBy($owner)->explicit()->create([
            'title' => 'Secret Title',
            'author' => 'Secret Author',
            'pageCount' => 42,
            'coverImagePath' => 'covers/missing/cover.png',
        ])->object();
        $recipient = $this->createAndLoginUser(['email' => 'unconfirmed@test.local']);
        $this->persistShare($comic, $owner, (string) $recipient->getEmail());

        $share = $this->getJson('/api/shares/shared-with-me')['sharedWithMe'][0];

        self::assertTrue($share['explicitContent']);
        self::assertTrue($share['requiresAdultConfirmation']);
        self::assertFalse($share['adultConfirmed']);
        // The comic id goes too: it is the key to every endpoint that serves a
        // cover, a page or an archive.
        self::assertNull($share['comicId']);
        self::assertNull($share['comicTitle']);
        self::assertNull($share['comicAuthor']);
        self::assertNull($share['pageCount']);
        self::assertNull($share['coverImagePath']);
        // What is left is enough to decide with: who is offering, and until when.
        self::assertSame('Alex Owner', $share['ownerName']);
        self::assertNotNull($share['expiresAt']);
    }

    public function testASignedOutTokenHolderLearnsNothingAboutAnExplicitComic(): void
    {
        $owner = UserFactory::createOne(['name' => 'Alex Owner'])->object();
        $comic = ComicFactory::new()->ownedBy($owner)->explicit()->create([
            'title' => 'Secret Title',
            'author' => 'Secret Author',
            'coverImagePath' => 'covers/missing/cover.png',
        ])->object();
        [, $plaintext] = $this->createPendingInvitation($comic, $owner, 'invited@test.local');

        // Possession of the link is not an age declaration — nobody has said who
        // they are yet — so it buys only a description of the invitation.
        $this->client->request('GET', '/api/shares/invitations/' . $plaintext, [], [], ['HTTP_ACCEPT' => 'application/json']);
        self::assertResponseIsSuccessful();

        $invitation = $this->json()['invitation'];
        self::assertTrue($invitation['explicitContent']);
        self::assertTrue($invitation['requiresAdultConfirmation']);
        self::assertNull($invitation['comicTitle']);
        self::assertNull($invitation['comicAuthor']);
        self::assertNull($invitation['pageCount']);
        self::assertNull($invitation['coverImagePath']);
        self::assertSame('Alex Owner', $invitation['ownerName']);
    }

    public function testAnUnconfirmedExplicitInvitationCannotBeAccepted(): void
    {
        $owner = UserFactory::createOne()->object();
        $comic = ComicFactory::new()->ownedBy($owner)->explicit()->create()->object();
        $recipient = $this->createAndLoginUser(['email' => 'eager@test.local']);
        $share = $this->persistShare($comic, $owner, (string) $recipient->getEmail());

        $payload = $this->postJson('/api/shares/' . $share->getId() . '/accept');
        self::assertResponseStatusCodeSame(403);
        self::assertSame(ShareException::CODE_ADULT_CONFIRMATION_REQUIRED, $payload['code']);

        $this->refresh();
        self::assertSame(ComicShare::STATUS_PENDING, $this->onlyShare()->getStatus());
    }

    public function testAnUnconfirmedExplicitLinkCannotBeAcceptedAndKeepsItsToken(): void
    {
        $owner = UserFactory::createOne()->object();
        $comic = ComicFactory::new()->ownedBy($owner)->explicit()->create()->object();
        $recipient = $this->createAndLoginUser(['email' => 'linked@test.local']);
        [, $plaintext] = $this->createPendingInvitation($comic, $owner, (string) $recipient->getEmail());

        $payload = $this->postJson('/api/shares/invitations/' . $plaintext . '/accept');
        self::assertResponseStatusCodeSame(403);
        self::assertSame(ShareException::CODE_ADULT_CONFIRMATION_REQUIRED, $payload['code']);

        // Being sent to the warning must not cost the recipient the link they
        // need once they have answered it.
        $this->postJson('/api/shares/invitations/' . $plaintext . '/confirm-adult', ['adultConfirmed' => true]);
        self::assertResponseIsSuccessful();
        $this->postJson('/api/shares/invitations/' . $plaintext . '/accept');
        self::assertResponseIsSuccessful();
    }

    /* ---------------------------------------------------------------------- */
    /* Making the declaration                                                  */
    /* ---------------------------------------------------------------------- */

    public function testOnlyTheIntendedRecipientCanConfirmTheirAge(): void
    {
        $owner = UserFactory::createOne()->object();
        $comic = ComicFactory::new()->ownedBy($owner)->explicit()->create()->object();
        $share = $this->persistShare($comic, $owner, 'intended@test.local');

        // Reported as missing rather than forbidden, so share ids cannot be
        // probed by anyone who is not a party to them.
        $this->createAndLoginUser(['email' => 'stranger@test.local']);
        $this->postJson('/api/shares/' . $share->getId() . '/confirm-adult', ['adultConfirmed' => true]);
        self::assertResponseStatusCodeSame(404);

        $this->refresh();
        self::assertNull($this->onlyShare()->getAdultConfirmedAt());
    }

    public function testATokenHolderWhoIsNotTheRecipientCannotConfirm(): void
    {
        $owner = UserFactory::createOne()->object();
        $comic = ComicFactory::new()->ownedBy($owner)->explicit()->create()->object();
        [, $plaintext] = $this->createPendingInvitation($comic, $owner, 'intended@test.local');

        $this->createAndLoginUser(['email' => 'forwarded-to@test.local']);
        $this->postJson('/api/shares/invitations/' . $plaintext . '/confirm-adult', ['adultConfirmed' => true]);
        self::assertResponseStatusCodeSame(403);

        $this->refresh();
        self::assertNull($this->onlyShare()->getAdultConfirmedAt());
    }

    public function testConfirmationRequiresTheDeclarationItself(): void
    {
        $owner = UserFactory::createOne()->object();
        $comic = ComicFactory::new()->ownedBy($owner)->explicit()->create()->object();
        $recipient = $this->createAndLoginUser(['email' => 'silent@test.local']);
        $share = $this->persistShare($comic, $owner, (string) $recipient->getEmail());

        foreach ([[], ['adultConfirmed' => false], ['adultConfirmed' => 'yes']] as $body) {
            $payload = $this->postJson('/api/shares/' . $share->getId() . '/confirm-adult', $body);
            self::assertResponseStatusCodeSame(400);
            self::assertSame(ShareException::CODE_ADULT_CONFIRMATION_REQUIRED, $payload['code']);
        }

        $this->refresh();
        self::assertNull($this->onlyShare()->getAdultConfirmedAt());
    }

    public function testConfirmingUnlocksTheMetadataAndStoresTheTimestampOnTheSameShare(): void
    {
        $owner = UserFactory::createOne()->object();
        $comic = ComicFactory::new()->ownedBy($owner)->explicit()->create([
            'title' => 'Now Visible',
            'author' => 'Some Author',
            'pageCount' => 42,
            'coverImagePath' => 'covers/missing/cover.png',
        ])->object();
        $recipient = $this->createAndLoginUser(['email' => 'grown-up@test.local']);
        $this->postInvitationAsOwner($comic, $owner, (string) $recipient->getEmail());
        $this->loginAs($recipient);
        $share = $this->onlyShare();

        $payload = $this->postJson(
            '/api/shares/' . $share->getId() . '/confirm-adult',
            ['adultConfirmed' => true]
        );
        self::assertResponseIsSuccessful();

        // The response is the now-unlocked view, from the request that unlocked it.
        self::assertFalse($payload['share']['requiresAdultConfirmation']);
        self::assertTrue($payload['share']['adultConfirmed']);
        self::assertSame('Now Visible', $payload['share']['comicTitle']);
        self::assertSame('Some Author', $payload['share']['comicAuthor']);
        self::assertSame(42, $payload['share']['pageCount']);
        self::assertSame($comic->getId(), $payload['share']['comicId']);
        self::assertNotNull($payload['share']['coverImagePath']);

        // Both acknowledgements are one row: this share is the whole audit trail.
        $this->refresh();
        $stored = $this->onlyShare();
        self::assertNotNull($stored->getAdultConfirmedAt());
        self::assertNotNull($stored->getSenderResponsibilityAcceptedAt());
    }

    public function testTheConfirmationTimestampIsTheServersAndSurvivesRepetition(): void
    {
        $owner = UserFactory::createOne()->object();
        $comic = ComicFactory::new()->ownedBy($owner)->explicit()->create()->object();
        $recipient = $this->createAndLoginUser(['email' => 'insistent@test.local']);
        $share = $this->persistShare($comic, $owner, (string) $recipient->getEmail());

        $this->postJson('/api/shares/' . $share->getId() . '/confirm-adult', [
            'adultConfirmed' => true,
            'adultConfirmedAt' => '1999-01-01T00:00:00+00:00',
        ]);
        self::assertResponseIsSuccessful();

        $this->refresh();
        $first = $this->onlyShare()->getAdultConfirmedAt();
        self::assertNotNull($first);
        self::assertGreaterThan(new \DateTimeImmutable('-1 minute'), $first);

        // Confirming again is a double click or a retry, not a new declaration.
        $this->postJson('/api/shares/' . $share->getId() . '/confirm-adult', ['adultConfirmed' => true]);
        self::assertResponseIsSuccessful();

        $this->refresh();
        self::assertEquals($first, $this->onlyShare()->getAdultConfirmedAt());
    }

    public function testConfirmationIsRefusedForSharesThatCannotBeActedOn(): void
    {
        $cases = [
            'revoked' => static fn (ComicShare $share) => $share->markRevoked(),
            'declined' => static fn (ComicShare $share) => $share->markDeclined(),
            'expired' => static fn (ComicShare $share) => $share->markPending(new \DateTimeImmutable('-1 day')),
            'tombstoned' => static fn (ComicShare $share) => $share->markUnavailable(ComicShare::REASON_OWNER_DELETED),
        ];

        foreach ($cases as $label => $spoil) {
            $owner = UserFactory::createOne()->object();
            $comic = ComicFactory::new()->ownedBy($owner)->explicit()->create()->object();
            $recipient = $this->createAndLoginUser(['email' => $label . '@test.local']);
            $share = $this->persistShare($comic, $owner, (string) $recipient->getEmail());

            $spoil($this->managed(ComicShare::class, (int) $share->getId()));
            $this->flush();

            $this->postJson('/api/shares/' . $share->getId() . '/confirm-adult', ['adultConfirmed' => true]);
            self::assertResponseStatusCodeSame(
                $label === 'declined' ? 409 : 410,
                sprintf('A %s share must not be confirmable.', $label)
            );
        }
    }

    public function testANonExplicitShareHasNothingToConfirm(): void
    {
        $owner = UserFactory::createOne()->object();
        $comic = ComicFactory::new()->ownedBy($owner)->create()->object();
        $recipient = $this->createAndLoginUser(['email' => 'ordinary@test.local']);
        $share = $this->persistShare($comic, $owner, (string) $recipient->getEmail());

        $this->postJson('/api/shares/' . $share->getId() . '/confirm-adult', ['adultConfirmed' => true]);
        self::assertResponseStatusCodeSame(409);

        // The ordinary flow is untouched: accepting still works, with no extra step.
        $this->postJson('/api/shares/' . $share->getId() . '/accept');
        self::assertResponseIsSuccessful();
    }

    /* ---------------------------------------------------------------------- */
    /* Reading, and the gate closing again                                     */
    /* ---------------------------------------------------------------------- */

    public function testAConfirmedRecipientReadsAnExplicitComicNormally(): void
    {
        $owner = UserFactory::createOne()->object();
        $comic = ComicFactory::new()->ownedBy($owner)->explicit()->create([
            'coverImagePath' => 'covers/missing/cover.png',
        ])->object();
        $recipient = $this->createAndLoginUser(['email' => 'reader@test.local']);
        $share = $this->persistShare($comic, $owner, (string) $recipient->getEmail());

        $this->postJson('/api/shares/' . $share->getId() . '/confirm-adult', ['adultConfirmed' => true]);
        self::assertResponseIsSuccessful();
        $this->postJson('/api/shares/' . $share->getId() . '/accept');
        self::assertResponseIsSuccessful();

        $this->getJson('/api/comics/' . $comic->getId());
        self::assertResponseIsSuccessful();
        $this->requestCover($owner, $comic);
        self::assertResponseIsSuccessful();
        self::assertSame([$comic->getId()], array_column($this->getJson('/api/comics')['comics'], 'id'));
    }

    public function testMarkingAnAlreadySharedComicExplicitClosesTheGateAgain(): void
    {
        $owner = UserFactory::createOne()->object();
        $comic = ComicFactory::new()->ownedBy($owner)->create([
            'coverImagePath' => 'covers/missing/cover.png',
        ])->object();
        $recipient = UserFactory::createOne(['email' => 'regated@test.local'])->object();

        $this->loginAs($recipient);
        $share = $this->createAcceptedShare($comic, $owner, $recipient);
        $this->getJson('/api/comics/' . $comic->getId());
        self::assertResponseIsSuccessful();

        $this->loginAs($owner);
        $this->patchJson('/api/comics/' . $comic->getId(), ['explicitContent' => true]);
        self::assertResponseIsSuccessful();

        // Fails closed. They agreed to read something that was not classified
        // 18+, so nothing they said then answers for the comic as it is now.
        $this->loginAs($recipient);
        $this->getJson('/api/comics/' . $comic->getId());
        self::assertResponseStatusCodeSame(403);
        // Not only the metadata: the endpoints that serve the actual bytes are
        // behind the same voter, so an accepted share cannot route around it.
        $this->requestCover($owner, $comic);
        self::assertResponseStatusCodeSame(403);
        $this->browser()->request('GET', '/api/comics/' . $comic->getId() . '/pages/1');
        self::assertResponseStatusCodeSame(404);
        self::assertSame([], $this->getJson('/api/comics')['comics']);

        // The relationship survived: it is reading that stopped, not the share.
        $received = $this->getJson('/api/shares/shared-with-me')['sharedWithMe'][0];
        self::assertSame(ComicShare::STATUS_ACCEPTED, $received['status']);
        self::assertTrue($received['requiresAdultConfirmation']);
        self::assertFalse($received['canRead']);
        self::assertNull($received['comicTitle']);

        // And one declaration is all it takes to get back in.
        $this->postJson('/api/shares/' . $share->getId() . '/confirm-adult', ['adultConfirmed' => true]);
        self::assertResponseIsSuccessful();
        $this->getJson('/api/comics/' . $comic->getId());
        self::assertResponseIsSuccessful();
    }

    public function testRegatingTouchesOnlyTheSharesThatHaveSomethingToReset(): void
    {
        $owner = UserFactory::createOne()->object();
        // Explicit from the start: a confirmation can only exist on a comic that
        // was asking for one, so this is the only way to arrive at a share that
        // has something to withdraw.
        $comic = ComicFactory::new()->ownedBy($owner)->explicit()->create()->object();
        $confirmed = UserFactory::createOne(['email' => 'confirmed@test.local'])->object();
        $neverAsked = UserFactory::createOne(['email' => 'never-asked@test.local'])->object();

        $this->loginAs($confirmed);
        $confirmedShare = $this->persistShare($comic, $owner, (string) $confirmed->getEmail());
        $this->postJson('/api/shares/' . $confirmedShare->getId() . '/confirm-adult', ['adultConfirmed' => true]);
        self::assertResponseIsSuccessful();
        $this->postJson('/api/shares/' . $confirmedShare->getId() . '/accept');
        self::assertResponseIsSuccessful();

        // Live, and still behind the gate: nothing to reset.
        $this->loginAs($neverAsked);
        $this->persistShare($comic, $owner, (string) $neverAsked->getEmail());

        $regated = static::getContainer()->get(ComicShareService::class)
            ->regateSharesForComic($this->managed(Comic::class, (int) $comic->getId()));

        // One, not two. The query is narrowed to the shares with a declaration
        // to withdraw, so a comic shared with many people and confirmed by few
        // loads the few — which is where the cost of re-gating actually is.
        self::assertSame(1, $regated);

        $this->flush();
        $this->refresh();
        self::assertNull($this->managed(ComicShare::class, (int) $confirmedShare->getId())->getAdultConfirmedAt());
    }

    public function testRegatingReachesSharesThatAreAlreadyLoaded(): void
    {
        $owner = UserFactory::createOne()->object();
        $comic = ComicFactory::new()->ownedBy($owner)->explicit()->create()->object();
        $recipient = UserFactory::createOne(['email' => 'in-memory@test.local'])->object();

        $this->loginAs($recipient);
        $share = $this->persistShare($comic, $owner, (string) $recipient->getEmail());
        $this->postJson('/api/shares/' . $share->getId() . '/confirm-adult', ['adultConfirmed' => true]);
        self::assertResponseIsSuccessful();

        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $managedShare = $this->managed(ComicShare::class, (int) $share->getId());
        // The precondition the rest of this test is about. Asserted, because a
        // guard that starts from an already-null field proves nothing.
        self::assertNotNull($managedShare->getAdultConfirmedAt());

        static::getContainer()->get(ComicShareService::class)
            ->regateSharesForComic($this->managed(Comic::class, (int) $comic->getId()));

        // Re-gating goes through the ORM on purpose, and this is what says so.
        //
        // A bulk DQL UPDATE would be one query, but it writes round the identity
        // map: this share is already loaded, and it would go on reporting a
        // confirmation the database no longer holds — so anything serializing it
        // later in the request would tell the recipient the gate was open. It
        // would also commit on its own, splitting the single flush that
        // ComicController::update() documents.
        self::assertNull($managedShare->getAdultConfirmedAt());

        // And it still reaches storage on the caller's flush, not before it.
        $entityManager->flush();
        $this->refresh();
        self::assertNull($this->managed(ComicShare::class, (int) $share->getId())->getAdultConfirmedAt());
    }

    public function testUnmarkingAComicRestoresAccessImmediately(): void
    {
        $owner = UserFactory::createOne()->object();
        $comic = ComicFactory::new()->ownedBy($owner)->create()->object();
        $recipient = UserFactory::createOne(['email' => 'reprieved@test.local'])->object();

        $this->loginAs($recipient);
        $this->createAcceptedShare($comic, $owner, $recipient);

        $this->loginAs($owner);
        $this->patchJson('/api/comics/' . $comic->getId(), ['explicitContent' => true]);

        $this->loginAs($recipient);
        $this->getJson('/api/comics/' . $comic->getId());
        self::assertResponseStatusCodeSame(403);

        $this->loginAs($owner);
        $this->patchJson('/api/comics/' . $comic->getId(), ['explicitContent' => false]);
        self::assertResponseIsSuccessful();

        // Nothing to re-confirm: there is no longer anything to confirm about.
        $this->loginAs($recipient);
        $this->getJson('/api/comics/' . $comic->getId());
        self::assertResponseIsSuccessful();
    }

    public function testTheGateAlsoClosesThroughTheDashboardBatchUpdate(): void
    {
        // The edit dialog on the dashboard saves through PATCH /api/comics, not
        // the single-comic route, so the re-gate has to hold on both.
        $owner = UserFactory::createOne()->object();
        $comic = ComicFactory::new()->ownedBy($owner)->create()->object();
        $recipient = UserFactory::createOne(['email' => 'batched@test.local'])->object();

        $this->loginAs($recipient);
        $this->createAcceptedShare($comic, $owner, $recipient);

        $this->loginAs($owner);
        $this->patchJson('/api/comics', [
            'updates' => [['id' => $comic->getId(), 'changes' => ['explicitContent' => true]]],
        ]);
        self::assertResponseIsSuccessful();

        $this->loginAs($recipient);
        $this->getJson('/api/comics/' . $comic->getId());
        self::assertResponseStatusCodeSame(403);
    }

    public function testATombstoneOfAnUnconfirmedExplicitComicStillWithholdsItsTitle(): void
    {
        $owner = UserFactory::createOne()->object();
        $comic = ComicFactory::new()->ownedBy($owner)->explicit()->create(['title' => 'Never Revealed'])->object();
        $recipient = UserFactory::createOne(['email' => 'bereaved@test.local'])->object();

        $this->loginAs($recipient);
        $this->persistShare($comic, $owner, (string) $recipient->getEmail());

        $this->loginAs($owner);
        $this->browser()->request('DELETE', '/api/comics/' . $comic->getId(), [], [], $this->csrfHeader());
        self::assertResponseIsSuccessful();

        // Deleting the comic must not be the way its title gets past the gate:
        // the snapshot a tombstone keeps is exactly what was being withheld.
        $this->loginAs($recipient);
        $received = $this->getJson('/api/shares/shared-with-me')['sharedWithMe'][0];
        self::assertTrue($received['isTombstoned']);
        self::assertTrue($received['requiresAdultConfirmation']);
        self::assertNull($received['comicTitle']);
    }

    public function testAnOwnerSeesTheirOwnExplicitComicUnredacted(): void
    {
        $owner = $this->createAndLoginUser(['email' => 'proprietor@test.local']);
        $comic = ComicFactory::new()->ownedBy($owner)->explicit()->create(['title' => 'Mine To See'])->object();

        $this->postInvitation((int) $comic->getId(), 'guest@example.com');

        // An age gate protects a recipient from content they have not agreed to
        // see. It is not a lock on somebody's own library.
        $group = $this->getJson('/api/shares/shared-by-me')['sharedByMe'][0];
        self::assertSame('Mine To See', $group['title']);
        self::assertTrue($group['explicitContent']);
        self::assertSame('Mine To See', $group['recipients'][0]['comicTitle']);
    }

    /* ---------------------------------------------------------------------- */
    /* Helpers                                                                 */
    /* ---------------------------------------------------------------------- */

    /** @param array<string, mixed> $extra */
    private function postInvitation(int $comicId, string $email, array $extra = []): array
    {
        return $this->postJson(
            '/api/shares/comics/' . $comicId . '/invitations',
            array_merge(['email' => $email, 'senderResponsibilityAccepted' => true], $extra)
        );
    }

    /** Invite as the owner, leaving the caller to log back in as they please. */
    private function postInvitationAsOwner(Comic $comic, User $owner, string $email): void
    {
        $this->loginAs($owner);
        $this->postInvitation((int) $comic->getId(), $email);
        self::assertResponseStatusCodeSame(201);
    }

    private function requestCover(User $owner, Comic $comic): void
    {
        $this->browser()->request(
            'GET',
            sprintf('/api/comics/cover/%d/%d/cover.png', $owner->getId(), $comic->getId())
        );
    }

    private function createAcceptedShare(Comic $comic, User $owner, User $recipient): ComicShare
    {
        $share = $this->persistShare($comic, $owner, (string) $recipient->getEmail());

        static::getContainer()->get(ComicShareService::class)
            ->acceptShare($share, $this->managed(User::class, (int) $recipient->getId()));

        return $share;
    }

    /** @return array{0: ComicShare, 1: string} */
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
        $share->markPending(new \DateTimeImmutable('+7 days'))->acceptSenderResponsibility();

        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($share);
        $entityManager->flush();

        return $share;
    }

    /** The single share these tests create, read back from storage. */
    private function onlyShare(): ComicShare
    {
        $shares = static::getContainer()->get(EntityManagerInterface::class)
            ->getRepository(ComicShare::class)
            ->findAll();
        self::assertCount(1, $shares);

        return $shares[0];
    }

    private function flush(): void
    {
        static::getContainer()->get(EntityManagerInterface::class)->flush();
    }

    /** Forget everything in memory, so the next read comes from the database. */
    private function refresh(): void
    {
        static::getContainer()->get(EntityManagerInterface::class)->clear();
    }

    /**
     * Look an entity up again in the entity manager the container is handing
     * out now — every request leaves the previous one detached.
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
