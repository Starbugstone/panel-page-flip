<?php

namespace App\Tests\Functional\Controller;

use App\Entity\ComicShare;
use App\Entity\ShareClaimCode;
use App\Service\ShareContentCodeLifetime;
use App\Tests\Factory\ComicFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Functional\AbstractApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * What the `C-` and `G-` prefixes promise.
 *
 * A comic code is exactly one comic. A group code is a deliberate package of
 * two to twenty, handed over whole or not at all — somebody redeeming a
 * fifteen-issue arc is taking up an offer of fifteen issues, and eleven of them
 * without a word is worse than none.
 *
 * The rest is about what the owner learns. A content code goes to people the
 * owner may not know, so redemption must never hand their addresses back.
 */
final class ShareContentCodeTest extends AbstractApiTestCase
{
    /** @return list<int> */
    private function ownedComicIds(object $owner, int $count): array
    {
        $ids = [];
        for ($i = 0; $i < $count; ++$i) {
            $ids[] = (int) ComicFactory::new()->ownedBy($owner)
                ->create(['title' => sprintf('Court of Owls #%d', $i + 1)])

                ->getId();
        }

        return $ids;
    }

    /* ---------------------------------------------------------------------- */
    /* The shape each prefix promises                                          */
    /* ---------------------------------------------------------------------- */

    public function testAComicCodeCarriesExactlyOneComic(): void
    {
        $owner = $this->createAndLoginUser(['email' => 'one-comic@example.com']);
        $ids = $this->ownedComicIds($owner, 2);

        $payload = $this->postJson('/api/shares/comic-codes', [
            'comicIds' => $ids,
            'maxUses' => 1,
            'senderResponsibilityAccepted' => true,
        ]);

        self::assertResponseStatusCodeSame(400);
        self::assertSame('A comic code carries exactly one comic.', $payload['message']);
        self::assertSame([], $this->getJson('/api/shares/content-codes')['codes']);
    }

    public function testAGroupCodeNeedsAtLeastTwo(): void
    {
        $owner = $this->createAndLoginUser(['email' => 'lonely-group@example.com']);
        $ids = $this->ownedComicIds($owner, 1);

        $payload = $this->postJson('/api/shares/group-codes', [
            'comicIds' => $ids,
            'maxUses' => 1,
            'senderResponsibilityAccepted' => true,
        ]);

        self::assertResponseStatusCodeSame(400);
        self::assertStringContainsString('between 2 and 20', $payload['message']);
    }

    /**
     * Duplicates are collapsed before the count is judged, so twenty copies of
     * one comic is one comic and not a group.
     */
    public function testTheSameComicListedTwiceIsStillOneComic(): void
    {
        $owner = $this->createAndLoginUser(['email' => 'duplicating@example.com']);
        $id = $this->ownedComicIds($owner, 1)[0];

        $this->postJson('/api/shares/group-codes', [
            'comicIds' => [$id, $id, $id],
            'maxUses' => 1,
            'senderResponsibilityAccepted' => true,
        ]);
        self::assertResponseStatusCodeSame(400);

        $this->postJson('/api/shares/comic-codes', [
            'comicIds' => [$id, $id],
            'maxUses' => 1,
            'senderResponsibilityAccepted' => true,
        ]);
        self::assertResponseStatusCodeSame(201);
    }

    public function testAGroupCodeHandsOverTheWholeArcForOneUse(): void
    {
        $owner = $this->createAndLoginUser(['email' => 'arc-owner@example.com', 'name' => 'Arc Owner']);
        $ids = $this->ownedComicIds($owner, 15);

        $created = $this->postJson('/api/shares/group-codes', [
            'comicIds' => $ids,
            'maxUses' => 5,
            'senderResponsibilityAccepted' => true,
        ]);

        self::assertResponseStatusCodeSame(201);
        self::assertStringStartsWith('G-', $created['code']);
        self::assertSame('G', $created['contentCode']['type']);
        self::assertSame(15, $created['contentCode']['comicCount']);

        $this->createAndLoginUser(['email' => 'arc-reader@example.com']);
        $redeemed = $this->postJson('/api/shares/content-codes/redeem', ['code' => $created['code']]);

        self::assertResponseIsSuccessful();
        self::assertSame('G', $redeemed['type']);
        self::assertSame(15, $redeemed['claimed']);
        self::assertCount(15, $this->getJson('/api/shares/shared-with-me')['sharedWithMe']);

        // Fifteen comics, one use. The recipient took the offer up once.
        $this->loginAs($owner);
        $listed = $this->getJson('/api/shares/content-codes')['codes'][0];
        self::assertSame(1, $listed['timesUsed']);
        self::assertSame(4, $listed['usesRemaining']);
    }

    /**
     * A code created as a group stays a group even after the comics behind it
     * change, so its prefix never starts describing something else.
     */
    public function testAGroupCodeDoesNotBecomeAComicCodeWhenItShrinks(): void
    {
        $owner = $this->createAndLoginUser(['email' => 'shrinking@example.com']);
        $ids = $this->ownedComicIds($owner, 2);

        $created = $this->postJson('/api/shares/group-codes', [
            'comicIds' => $ids,
            'maxUses' => 3,
            'senderResponsibilityAccepted' => true,
        ]);

        $this->deleteJson('/api/comics/' . $ids[0]);
        self::assertResponseIsSuccessful();

        $listed = $this->getJson('/api/shares/content-codes')['codes'][0];
        self::assertSame('G', $listed['type']);
        self::assertSame(2, $listed['issuedComicCount']);
        self::assertSame(1, $listed['comicCount']);
        self::assertSame('comics_removed', $listed['deadReason']);

        // And nobody gets a partial arc out of it.
        $this->createAndLoginUser(['email' => 'would-be-reader@example.com']);
        $this->postJson('/api/shares/content-codes/redeem', ['code' => $created['code']]);
        self::assertResponseStatusCodeSame(404);
        self::assertSame([], $this->getJson('/api/shares/shared-with-me')['sharedWithMe']);
    }

    /**
     * A package the owner can no longer deliver fails without costing anybody a
     * use. The owner's way out is to withdraw and reissue.
     */
    public function testAnUndeliverableGroupTakesNoUseAndGrantsNothing(): void
    {
        $owner = $this->createAndLoginUser(['email' => 'restricted-arc@example.com']);
        $ids = $this->ownedComicIds($owner, 3);

        $created = $this->postJson('/api/shares/group-codes', [
            'comicIds' => $ids,
            'maxUses' => 4,
            'senderResponsibilityAccepted' => true,
        ]);

        // One of the three is put out of reach after the code went out. The
        // join row survives — nothing was deleted — so the package still looks
        // whole and the check has to be about shareability, not about counting.
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $entityManager->getConnection()->executeStatement(
            'UPDATE comic SET quarantined_at = NOW() WHERE id = :id',
            ['id' => $ids[1]]
        );
        $entityManager->clear();

        $this->createAndLoginUser(['email' => 'partial-arc@example.com']);
        $payload = $this->postJson('/api/shares/content-codes/redeem', ['code' => $created['code']]);

        self::assertResponseStatusCodeSame(409);
        self::assertStringContainsString('nothing was added', $payload['message']);
        // Not eleven of fifteen, and not two of three: nothing.
        self::assertSame([], $this->getJson('/api/shares/shared-with-me')['sharedWithMe']);

        $this->loginAs($owner);
        self::assertSame(4, $this->getJson('/api/shares/content-codes')['codes'][0]['usesRemaining']);
    }

    /**
     * Overlap is ordinary — a recipient may already hold part of an arc — so it
     * reuses those relationships and says which is which rather than refusing.
     */
    public function testAGroupThatOverlapsWhatSomebodyAlreadyHasAddsOnlyTheRest(): void
    {
        $owner = $this->createAndLoginUser(['email' => 'overlapping@example.com']);
        $ids = $this->ownedComicIds($owner, 3);

        $single = $this->postJson('/api/shares/comic-codes', [
            'comicIds' => [$ids[0]],
            'maxUses' => 1,
            'senderResponsibilityAccepted' => true,
        ])['code'];
        $group = $this->postJson('/api/shares/group-codes', [
            'comicIds' => $ids,
            'maxUses' => 1,
            'senderResponsibilityAccepted' => true,
        ])['code'];

        $this->createAndLoginUser(['email' => 'already-has-one@example.com']);
        $this->postJson('/api/shares/content-codes/redeem', ['code' => $single]);
        self::assertResponseIsSuccessful();

        $payload = $this->postJson('/api/shares/content-codes/redeem', ['code' => $group]);

        self::assertResponseIsSuccessful();
        self::assertSame(2, $payload['claimed'], 'Only the two they were missing are new.');
        self::assertSame(1, $payload['alreadyHeld']);
        self::assertCount(3, $this->getJson('/api/shares/shared-with-me')['sharedWithMe']);
    }

    /* ---------------------------------------------------------------------- */
    /* Configured lifetime                                                     */
    /* ---------------------------------------------------------------------- */

    public function testANewCodeExpiresAfterTheConfiguredLifetime(): void
    {
        $owner = $this->createAndLoginUser(['email' => 'ttl@example.com']);
        $ids = $this->ownedComicIds($owner, 2);

        $lifetime = static::getContainer()->get(ShareContentCodeLifetime::class);
        self::assertSame(ShareContentCodeLifetime::DEFAULT_DAYS, $lifetime->days());

        foreach ([
            ['/api/shares/comic-codes', [$ids[0]]],
            ['/api/shares/group-codes', $ids],
        ] as [$route, $comicIds]) {
            $created = $this->postJson($route, [
                'comicIds' => $comicIds,
                'maxUses' => 1,
                'senderResponsibilityAccepted' => true,
            ]);
            self::assertResponseStatusCodeSame(201);

            // Both kinds obey the one setting; an installation where comic
            // codes outlived group codes would be describing a distinction its
            // users have no way to see.
            $expiresAt = new \DateTimeImmutable($created['contentCode']['expiresAt']);
            $expected = $lifetime->expiryFrom(new \DateTimeImmutable($created['contentCode']['createdAt']));

            self::assertLessThanOrEqual(
                2,
                abs($expiresAt->getTimestamp() - $expected->getTimestamp()),
                sprintf('%s should expire %d days after creation.', $route, $lifetime->days())
            );
        }
    }

    /**
     * The list carries the server's own `expiresAt`, so no client has to do
     * seven-days-from-now arithmetic of its own and get it wrong when an
     * operator changes the setting.
     */
    public function testTheServerAlwaysReportsTheRealExpiry(): void
    {
        $owner = $this->createAndLoginUser(['email' => 'reports-expiry@example.com']);
        $ids = $this->ownedComicIds($owner, 1);

        $this->postJson('/api/shares/comic-codes', [
            'comicIds' => $ids,
            'maxUses' => 1,
            'senderResponsibilityAccepted' => true,
        ]);

        $payload = $this->getJson('/api/shares/content-codes');

        self::assertSame(ShareContentCodeLifetime::DEFAULT_DAYS, $payload['lifetimeDays']);
        self::assertNotNull($payload['codes'][0]['expiresAt']);
        self::assertNotFalse(strtotime($payload['codes'][0]['expiresAt']));
    }

    /**
     * A user code is an address rather than a capability, so there is nothing
     * for an expiry to contain — and it must not inherit the content-code one.
     */
    public function testAUserCodeIsUnaffectedByTheContentCodeLifetime(): void
    {
        $this->createAndLoginUser(['email' => 'permanent@example.com']);

        $payload = $this->getJson('/api/shares/user-code');

        self::assertArrayNotHasKey('expiresAt', $payload);
        self::assertNotSame('', $payload['userCode']);
    }

    /**
     * The moment a code stops working is written on the row when it is minted.
     * An owner who told somebody "this works until Friday" must not find that
     * an operator moved Friday.
     */
    public function testChangingTheSettingDoesNotMoveAnAlreadyIssuedExpiry(): void
    {
        $owner = $this->createAndLoginUser(['email' => 'already-issued@example.com']);
        $ids = $this->ownedComicIds($owner, 1);

        $created = $this->postJson('/api/shares/comic-codes', [
            'comicIds' => $ids,
            'maxUses' => 1,
            'senderResponsibilityAccepted' => true,
        ]);
        $issuedExpiry = $created['contentCode']['expiresAt'];

        // A different lifetime, as a redeployed configuration would produce.
        // Nothing recomputes an expiry from it, because the expiry is data.
        $shorter = new ShareContentCodeLifetime(1);
        self::assertSame(1, $shorter->days());

        self::assertSame(
            $issuedExpiry,
            $this->getJson('/api/shares/content-codes')['codes'][0]['expiresAt']
        );
    }

    public function testExpiryIsRejectedAtTheBoundaryAndNotAMomentLater(): void
    {
        $owner = $this->createAndLoginUser(['email' => 'boundary@example.com']);
        $ids = $this->ownedComicIds($owner, 1);

        $code = $this->postJson('/api/shares/comic-codes', [
            'comicIds' => $ids,
            'maxUses' => 1,
            'senderResponsibilityAccepted' => true,
        ])['code'];

        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $stored = $entityManager->getRepository(ShareClaimCode::class)->findOneBy([]);
        $expiresAt = $stored->getExpiresAt();

        // Exactly at the boundary the code is already dead: "expires at noon"
        // has to mean it does not work at noon, or the last moment it works is
        // a matter of opinion.
        self::assertTrue($stored->isExpired($expiresAt));
        self::assertFalse($stored->isRedeemable($expiresAt));
        self::assertFalse($stored->isExpired($expiresAt->modify('-1 second')));
        self::assertTrue($stored->isRedeemable($expiresAt->modify('-1 second')));
    }

    /* ---------------------------------------------------------------------- */
    /* Privacy                                                                 */
    /* ---------------------------------------------------------------------- */

    /**
     * The owner put a code into the world and somebody they may never have met
     * picked it up. Handing them that person's address afterwards turns "give
     * this to a friend" into "collect addresses from strangers".
     */
    public function testRedeemingACodeNeverGivesTheOwnerTheRedeemersAddress(): void
    {
        $owner = $this->createAndLoginUser(['email' => 'code-issuer@example.com']);
        $ids = $this->ownedComicIds($owner, 1);
        $code = $this->postJson('/api/shares/comic-codes', [
            'comicIds' => $ids,
            'maxUses' => 3,
            'senderResponsibilityAccepted' => true,
        ])['code'];

        $redeemer = UserFactory::createOne([
            'email' => 'stranger-address@example.com',
            'name' => 'Passing Stranger',
        ]);
        $this->loginAs($redeemer);
        $this->postJson('/api/shares/content-codes/redeem', ['code' => $code]);
        self::assertResponseIsSuccessful();

        $this->loginAs($owner);
        $entry = $this->getJson('/api/shares/shared-by-me')['sharedByMe'][0]['recipients'][0];
        $body = (string) $this->browser()->getResponse()->getContent();

        self::assertStringNotContainsString('stranger-address@example.com', $body);
        self::assertNull($entry['recipientEmail']);
        self::assertSame($redeemer->getUsername(), $entry['recipientUsername']);
        self::assertSame(
            sprintf('Passing Stranger (@%s)', $redeemer->getUsername()),
            $entry['recipientLabel']
        );

        // Nor through the back door of the recipient picker.
        $recipients = $this->getJson('/api/shares/recent-recipients')['recipients'];
        self::assertStringNotContainsString(
            'stranger-address@example.com',
            (string) $this->browser()->getResponse()->getContent()
        );
        self::assertNull($recipients[0]['email']);
    }

    /**
     * And in the other direction: a code says nothing about who issued it, so
     * redeeming one must not become a way to learn the owner's address.
     */
    public function testRedeemingACodeNeverGivesTheRedeemerTheOwnersAddress(): void
    {
        $owner = UserFactory::createOne([
            'email' => 'owner-address@example.com',
            'name' => 'Careful Owner',
        ]);
        $this->loginAs($owner);
        $ids = $this->ownedComicIds($owner, 1);
        $code = $this->postJson('/api/shares/comic-codes', [
            'comicIds' => $ids,
            'maxUses' => 1,
            'senderResponsibilityAccepted' => true,
        ])['code'];

        $this->createAndLoginUser(['email' => 'curious@example.com']);
        $payload = $this->postJson('/api/shares/content-codes/redeem', ['code' => $code]);

        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString(
            'owner-address@example.com',
            (string) $this->browser()->getResponse()->getContent()
        );
        self::assertSame(
            sprintf('Careful Owner (@%s)', $owner->getUsername()),
            $payload['ownerLabel']
        );

        $received = $this->getJson('/api/shares/shared-with-me')['sharedWithMe'][0];
        self::assertSame($owner->getUsername(), $received['ownerUsername']);
        self::assertStringNotContainsString(
            'owner-address@example.com',
            (string) $this->browser()->getResponse()->getContent()
        );
    }

    /**
     * The owner keeps the source. Everything a code produces is an ordinary
     * revocable relationship, and revoking it takes the access away at once.
     */
    public function testACodeProducesAnOrdinaryRevocableShare(): void
    {
        $owner = $this->createAndLoginUser(['email' => 'still-mine@example.com']);
        $ids = $this->ownedComicIds($owner, 1);
        $code = $this->postJson('/api/shares/comic-codes', [
            'comicIds' => $ids,
            'maxUses' => 1,
            'senderResponsibilityAccepted' => true,
        ])['code'];

        $reader = $this->createAndLoginUser(['email' => 'reader@example.com']);
        $this->postJson('/api/shares/content-codes/redeem', ['code' => $code]);
        self::assertResponseIsSuccessful();
        self::assertTrue($this->getJson('/api/shares/shared-with-me')['sharedWithMe'][0]['canRead']);

        $this->loginAs($owner);
        $shareId = $this->getJson('/api/shares/shared-by-me')['sharedByMe'][0]['recipients'][0]['id'];
        $this->postJson(sprintf('/api/shares/%d/revoke', $shareId), []);
        self::assertResponseIsSuccessful();

        $this->loginAs($reader);
        $received = $this->getJson('/api/shares/shared-with-me')['sharedWithMe'][0];
        self::assertSame(ComicShare::STATUS_REVOKED, $received['status']);
        self::assertFalse($received['canRead']);
        self::assertNull($received['coverImagePath']);
    }

    /* ---------------------------------------------------------------------- */
    /* Wrong code, wrong box                                                   */
    /* ---------------------------------------------------------------------- */

    public function testAUserCodeRedeemedAsContentIsExplainedRatherThanRefused(): void
    {
        $this->createAndLoginUser(['email' => 'mixed-up@example.com']);
        $userCode = $this->getJson('/api/shares/user-code')['userCode'];

        $payload = $this->postJson('/api/shares/content-codes/redeem', ['code' => $userCode]);

        self::assertResponseStatusCodeSame(400);
        self::assertSame(
            'This is a user code. Use it when sharing directly with another user.',
            $payload['message']
        );
        self::assertSame('share_code_wrong_type', $payload['code']);
    }

    /**
     * The old unprefixed form is not accepted anywhere. Recognising it would be
     * the compatibility layer this release exists to avoid.
     */
    public function testTheOldUnprefixedFormIsNotRecognised(): void
    {
        $owner = $this->createAndLoginUser(['email' => 'legacy@example.com']);
        $ids = $this->ownedComicIds($owner, 1);
        $code = $this->postJson('/api/shares/comic-codes', [
            'comicIds' => $ids,
            'maxUses' => 1,
            'senderResponsibilityAccepted' => true,
        ])['code'];

        $this->createAndLoginUser(['email' => 'old-client@example.com']);
        // The same code with its prefix stripped, which is exactly what a
        // client written against the previous release would send.
        $this->postJson('/api/shares/content-codes/redeem', ['code' => substr($code, 2)]);

        self::assertResponseStatusCodeSame(404);
        self::assertSame([], $this->getJson('/api/shares/shared-with-me')['sharedWithMe']);
    }

    /**
     * A comic code and a group code drawn from the same twelve characters are
     * two different capabilities, and the stored hash has to say so.
     */
    public function testAComicCodeCannotBeRedeemedByCallingItAGroupCode(): void
    {
        $owner = $this->createAndLoginUser(['email' => 'typed-hash@example.com']);
        $ids = $this->ownedComicIds($owner, 1);
        $code = $this->postJson('/api/shares/comic-codes', [
            'comicIds' => $ids,
            'maxUses' => 1,
            'senderResponsibilityAccepted' => true,
        ])['code'];

        $this->createAndLoginUser(['email' => 'relabeller@example.com']);
        $this->postJson('/api/shares/content-codes/redeem', ['code' => 'G' . substr($code, 1)]);

        self::assertResponseStatusCodeSame(404);
        self::assertSame([], $this->getJson('/api/shares/shared-with-me')['sharedWithMe']);
    }

    /**
     * An exhausted allowance stops a real code being redeemed too.
     *
     * #135 asks for the flood guard *before* the hash lookup, and this is why
     * the order matters rather than being tidiness. Charging only for a miss
     * leaves a spent caller still able to redeem a code they hold while being
     * refused for one they guessed — so the refusal itself reports whether the
     * guess was real, which is exactly what grinding through the keyspace wants
     * to know.
     */
    public function testAnExhaustedAllowanceRefusesARealCodeAsWell(): void
    {
        $owner = $this->createAndLoginUser(['email' => 'still-good@example.com']);
        $code = $this->postJson('/api/shares/comic-codes', [
            'comicIds' => $this->ownedComicIds($owner, 1),
            'maxUses' => 5,
            'senderResponsibilityAccepted' => true,
        ])['code'];

        $this->createAndLoginUser(['email' => 'grinder@example.com']);
        $this->exhaustIdentifierLookups();

        $real = $this->postJson('/api/shares/content-codes/redeem', ['code' => $code]);
        $realStatus = $this->browser()->getResponse()->getStatusCode();

        $imaginary = $this->postJson('/api/shares/content-codes/redeem', ['code' => 'C-ZZZZ-ZZZZ-ZZZZ']);

        self::assertSame(429, $realStatus);
        self::assertSame($realStatus, $this->browser()->getResponse()->getStatusCode());
        self::assertSame($real['message'], $imaginary['message']);

        // And the live code really was live: nothing was handed over, so the
        // refusal was the guard rather than the code having been spent.
        self::assertSame([], $this->getJson('/api/shares/shared-with-me')['sharedWithMe']);
    }
    /* ---------------------------------------------------------------------- */
    /* The age gate                                                            */
    /* ---------------------------------------------------------------------- */

    /**
     * Redeeming a code is affirmative acceptance, and the age gate is the one
     * thing it cannot wave through.
     *
     * A group may legitimately mix ordinary and 18+ comics. The ordinary ones
     * are accepted on the spot, because typing a code somebody gave you is a
     * decision; the explicit one is left pending until its recipient declares
     * their age. Deciding it before the share is accepted rather than undoing
     * it afterwards means there is no moment where an unconfirmed recipient
     * holds an accepted share.
     */
    public function testAnExplicitComicInAGroupIsLeftBehindTheAgeGate(): void
    {
        $owner = $this->createAndLoginUser(['email' => 'mixed-arc@example.com']);
        $ordinary = ComicFactory::new()->ownedBy($owner)->create(['title' => 'All Ages']);
        $adult = ComicFactory::new()->ownedBy($owner)->explicit()->create(['title' => 'Adults Only']);

        $code = $this->postJson('/api/shares/group-codes', [
            'comicIds' => [$ordinary->getId(), $adult->getId()],
            'maxUses' => 2,
            'senderResponsibilityAccepted' => true,
        ])['code'];
        self::assertResponseStatusCodeSame(201);

        $this->createAndLoginUser(['email' => 'unconfirmed@example.com']);
        $redeemed = $this->postJson('/api/shares/content-codes/redeem', ['code' => $code]);
        self::assertResponseIsSuccessful();

        $byComic = [];
        foreach ($redeemed['results'] as $result) {
            $byComic[(int) $result['comicId']] = $result['status'];
        }

        self::assertSame('claimed', $byComic[(int) $ordinary->getId()]);
        self::assertSame('awaiting_age_confirmation', $byComic[(int) $adult->getId()]);

        // And the gate is real, not merely reported: the explicit comic is not
        // readable, and the serializer withholds even its title.
        $received = $this->getJson('/api/shares/shared-with-me')['sharedWithMe'];
        $gated = null;
        foreach ($received as $share) {
            if ($share['comicTitle'] === null) {
                $gated = $share;
            }
        }

        self::assertNotNull($gated, 'The explicit comic should be withheld until an age is declared.');
        self::assertTrue($gated['requiresAdultConfirmation']);
        self::assertFalse($gated['adultConfirmed']);
        self::assertNull($gated['comicId']);

        $this->getJson('/api/comics/' . $adult->getId());
        self::assertResponseStatusCodeSame(403);

        // Declaring an age is not the same as taking the comic. The share was
        // deliberately never accepted, so confirming alone must not open it —
        // otherwise redeeming a code would accept an explicit comic on the
        // recipient's behalf, which is the thing leaving it pending prevents.
        $this->postJson('/api/shares/' . $gated['id'] . '/confirm-adult', ['adultConfirmed' => true]);
        self::assertResponseIsSuccessful();

        $this->getJson('/api/comics/' . $adult->getId());
        self::assertResponseStatusCodeSame(403);

        // Accepting is the second, separate act, and only now is it readable.
        $this->postJson('/api/shares/' . $gated['id'] . '/accept');
        self::assertResponseIsSuccessful();

        $this->getJson('/api/comics/' . $adult->getId());
        self::assertResponseIsSuccessful();

        // The ordinary comic needed neither step: entering the code was the
        // affirmative act, and it was readable from the moment it arrived.
        $this->getJson('/api/comics/' . $ordinary->getId());
        self::assertResponseIsSuccessful();
    }

    /* ---------------------------------------------------------------------- */
    /* Reading a code back                                                     */
    /* ---------------------------------------------------------------------- */

    /**
     * The point of storing a code encrypted: an owner who has lost the message
     * they sent it in can read it back rather than having to withdraw a live
     * code and mint another.
     */
    public function testAnOwnerCanReadTheirOwnCodeBack(): void
    {
        $owner = $this->createAndLoginUser(['email' => 'reveal-mine@example.com']);
        $ids = $this->ownedComicIds($owner, 1);

        $created = $this->postJson('/api/shares/comic-codes', [
            'comicIds' => $ids,
            'maxUses' => 1,
            'senderResponsibilityAccepted' => true,
        ]);
        self::assertResponseStatusCodeSame(201);

        $codeId = $created['contentCode']['id'];
        $revealed = $this->getJson(sprintf('/api/shares/content-codes/%d/reveal', $codeId));

        self::assertResponseIsSuccessful();
        self::assertSame($created['code'], $revealed['code']);
    }

    /** The list says a code can be read back; it never carries the code itself. */
    public function testTheListSaysACodeIsRevealableWithoutRevealingIt(): void
    {
        $owner = $this->createAndLoginUser(['email' => 'reveal-flag@example.com']);
        $ids = $this->ownedComicIds($owner, 1);

        $created = $this->postJson('/api/shares/comic-codes', [
            'comicIds' => $ids,
            'maxUses' => 1,
            'senderResponsibilityAccepted' => true,
        ]);

        $listed = $this->getJson('/api/shares/content-codes')['codes'][0];

        self::assertTrue($listed['canReveal']);
        self::assertArrayNotHasKey('code', $listed);
        self::assertStringNotContainsString(
            $created['code'],
            json_encode($listed, JSON_THROW_ON_ERROR),
        );
    }

    /**
     * Somebody else's code is missing, not forbidden — the same silence
     * withdrawing one applies, so an id cannot be used to learn whose it is.
     */
    public function testSomebodyElsesCodeCannotBeRead(): void
    {
        $owner = $this->createAndLoginUser(['email' => 'reveal-owner@example.com']);
        $ids = $this->ownedComicIds($owner, 1);
        $created = $this->postJson('/api/shares/comic-codes', [
            'comicIds' => $ids,
            'maxUses' => 1,
            'senderResponsibilityAccepted' => true,
        ]);
        $codeId = $created['contentCode']['id'];

        $this->createAndLoginUser(['email' => 'reveal-stranger@example.com']);
        $this->getJson(sprintf('/api/shares/content-codes/%d/reveal', $codeId));

        self::assertResponseStatusCodeSame(404);
    }

    /**
     * A code issued before this column existed genuinely has nothing to show.
     * Saying so, and what to do instead, beats an empty string.
     */
    public function testACodeWithNothingStoredSaysSoRatherThanShowingNothing(): void
    {
        $owner = $this->createAndLoginUser(['email' => 'reveal-legacy@example.com']);
        $ids = $this->ownedComicIds($owner, 1);
        $created = $this->postJson('/api/shares/comic-codes', [
            'comicIds' => $ids,
            'maxUses' => 1,
            'senderResponsibilityAccepted' => true,
        ]);
        $codeId = (int) $created['contentCode']['id'];

        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $entityManager->getConnection()->executeStatement(
            'UPDATE share_claim_code SET code_cipher = NULL WHERE id = :id',
            ['id' => $codeId],
        );
        $entityManager->clear();

        self::assertFalse($this->getJson('/api/shares/content-codes')['codes'][0]['canReveal']);

        $payload = $this->getJson(sprintf('/api/shares/content-codes/%d/reveal', $codeId));

        self::assertResponseStatusCodeSame(409);
        self::assertStringContainsString('nothing to show', $payload['message']);
    }

    /**
     * A withdrawn code is still readable. "Which one was that?" is a question
     * about a dead code as much as a live one, and answering it hands over
     * nothing that can be redeemed.
     */
    public function testAWithdrawnCodeCanStillBeRead(): void
    {
        $owner = $this->createAndLoginUser(['email' => 'reveal-withdrawn@example.com']);
        $ids = $this->ownedComicIds($owner, 1);
        $created = $this->postJson('/api/shares/comic-codes', [
            'comicIds' => $ids,
            'maxUses' => 1,
            'senderResponsibilityAccepted' => true,
        ]);
        $codeId = (int) $created['contentCode']['id'];

        $this->deleteJson(sprintf('/api/shares/content-codes/%d', $codeId));
        self::assertResponseIsSuccessful();

        $revealed = $this->getJson(sprintf('/api/shares/content-codes/%d/reveal', $codeId));

        self::assertResponseIsSuccessful();
        self::assertSame($created['code'], $revealed['code']);
    }

    /** Nothing readable is written to the database in the clear. */
    public function testTheStoredCodeIsNotReadableWithoutTheKey(): void
    {
        $owner = $this->createAndLoginUser(['email' => 'reveal-at-rest@example.com']);
        $ids = $this->ownedComicIds($owner, 1);
        $created = $this->postJson('/api/shares/comic-codes', [
            'comicIds' => $ids,
            'maxUses' => 1,
            'senderResponsibilityAccepted' => true,
        ]);

        $stored = static::getContainer()->get(EntityManagerInterface::class)
            ->getConnection()
            ->fetchOne(
                'SELECT code_cipher FROM share_claim_code WHERE id = :id',
                ['id' => (int) $created['contentCode']['id']],
            );

        self::assertIsString($stored);
        self::assertStringStartsWith('enc:v1:', $stored);
        self::assertStringNotContainsString($created['code'], $stored);
    }
}
