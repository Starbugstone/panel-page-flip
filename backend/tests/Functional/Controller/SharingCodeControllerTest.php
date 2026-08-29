<?php

namespace App\Tests\Functional\Controller;

use App\Entity\Comic;
use App\Entity\ComicShare;
use App\Entity\ShareClaimCode;
use App\Entity\User;
use App\Enum\ShareCodeType;
use App\Service\SharingCodeFormat;
use App\Tests\Factory\ComicFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Functional\AbstractApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Sharing codes, in both directions.
 *
 * A **`U-` user code** is rotatable, belongs to the person who wants to be
 * shared with, and grants nothing: it only says who a share should be addressed
 * to, so the sender never has to be told an email address. A **`C-` comic code**
 * goes the other way — an owner hands it out and whoever redeems it gets the one
 * comic behind it — so it is disposable, expires, and is counted down.
 *
 * The tests here are mostly about what a code must *not* do: name an address,
 * survive being used up, outlive its configured lifetime, or answer differently
 * for a code that never existed than for one that has run out.
 *
 * The `G-` group code, the configured lifetime and the rules the prefixes buy
 * have their own file; see {@see ShareContentCodeTest}.
 */
final class SharingCodeControllerTest extends AbstractApiTestCase
{
    /** The shape every code is displayed in: a type letter, then twelve in threes. */
    private const CODE_PATTERN = '/^[UCG]-[0-9A-Z]{4}-[0-9A-Z]{4}-[0-9A-Z]{4}$/';

    public function testAUserCodeIsIssuedWithTheAccountAndNeverChangesOnItsOwn(): void
    {
        $user = $this->createAndLoginUser(['email' => 'stable@example.com', 'name' => 'Stable Reader']);

        $first = $this->getJson('/api/shares/user-code');
        self::assertResponseIsSuccessful();
        self::assertSame('Stable Reader', $first['name']);
        self::assertMatchesRegularExpression(self::CODE_PATTERN, $first['userCode']);
        self::assertStringStartsWith('U-', $first['userCode']);

        // The identity beside it, which is what a sender is actually shown.
        self::assertNotSame('', $first['username']);
        self::assertSame(sprintf('Stable Reader (@%s)', $first['username']), $first['label']);

        // Asked again, and again after the account has been reloaded from the
        // database: everybody who was ever given this code is holding it.
        self::assertSame($first['userCode'], $this->getJson('/api/shares/user-code')['userCode']);

        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $stored = $entityManager->getRepository(User::class)->find($user->getId());
        self::assertSame(
            $first['userCode'],
            SharingCodeFormat::forDisplay(ShareCodeType::USER, $stored->getUserCode())
        );
    }

    public function testResolvingACodeRevealsAPublicIdentityAndNothingElse(): void
    {
        $recipient = UserFactory::createOne([
            'email' => 'private-address@example.com',
            'name' => 'Jane Reader',
        ])->object();
        $this->loginAs($recipient);
        $code = $this->getJson('/api/shares/user-code')['userCode'];

        $this->createAndLoginUser(['email' => 'sender@example.com']);
        $payload = $this->postJson('/api/shares/user-code/resolve', ['userCode' => $code]);

        self::assertResponseIsSuccessful();
        self::assertSame('Jane Reader', $payload['recipient']['name']);
        // The username is what makes the confirmation mean anything: two people
        // can be called Jane Reader, and only one of them holds this handle.
        self::assertSame($recipient->getUsername(), $payload['recipient']['username']);
        // The address is the whole thing a user code exists to withhold.
        self::assertStringNotContainsString(
            'private-address@example.com',
            (string) $this->browser()->getResponse()->getContent()
        );
        self::assertArrayNotHasKey('email', $payload['recipient']);
        self::assertArrayNotHasKey('id', $payload['recipient']);
    }

    /**
     * A code that does not resolve says so in one way, whether it is malformed,
     * well formed and unused, or well formed and somebody else's. Telling those
     * apart would turn this into a way to find out which codes are real.
     */
    public function testAnUnknownCodeIsAnsweredTheSameWayHoweverItIsWrong(): void
    {
        $this->createAndLoginUser(['email' => 'prober@example.com']);

        $answers = [];
        foreach (['not-a-code', 'U-ZZZZ-ZZZZ-ZZZZ', 'U-0000-0000-0000'] as $attempt) {
            $payload = $this->postJson('/api/shares/user-code/resolve', ['userCode' => $attempt]);
            $answers[] = [$this->browser()->getResponse()->getStatusCode(), $payload['message']];
        }

        self::assertCount(1, array_unique($answers, SORT_REGULAR));
        self::assertSame(404, $answers[0][0]);
    }

    /**
     * A code of the wrong class is not a failed guess, and answering it like one
     * wastes the holder's time on something they could fix in a second.
     */
    public function testAContentCodePastedAsARecipientIsExplained(): void
    {
        $owner = $this->createAndLoginUser(['email' => 'confused@example.com']);
        $comic = ComicFactory::new()->ownedBy($owner)->create()->object();
        $comicCode = $this->postJson('/api/shares/comic-codes', [
            'comicIds' => [$comic->getId()],
            'maxUses' => 1,
            'senderResponsibilityAccepted' => true,
        ])['code'];

        $payload = $this->postJson('/api/shares/user-code/resolve', ['userCode' => $comicCode]);

        self::assertResponseStatusCodeSame(400);
        self::assertSame('This is a comic code. Redeem it under Shared with me.', $payload['message']);
        self::assertSame('share_code_wrong_type', $payload['code']);
    }

    public function testSharingByUserCodeNeverShowsTheSenderTheAddress(): void
    {
        $recipient = UserFactory::createOne([
            'email' => 'hidden@example.com',
            'name' => 'Hidden Reader',
        ])->object();
        $this->loginAs($recipient);
        $code = $this->getJson('/api/shares/user-code')['userCode'];

        $owner = $this->createAndLoginUser(['email' => 'code-sender@example.com']);
        $comic = ComicFactory::new()->ownedBy($owner)->create(['title' => 'Shared By Code'])->object();

        $payload = $this->postJson('/api/shares/invitations/bulk', [
            'comicIds' => [$comic->getId()],
            'userCode' => $code,
            'senderResponsibilityAccepted' => true,
        ]);

        self::assertResponseStatusCodeSame(201);
        self::assertSame(1, $payload['created']);

        // The sender's own page, which is where an address would leak back.
        $sharedByMe = $this->getJson('/api/shares/shared-by-me')['sharedByMe'];
        $body = (string) $this->browser()->getResponse()->getContent();
        self::assertStringNotContainsString('hidden@example.com', $body);

        $entry = $sharedByMe[0]['recipients'][0];
        self::assertNull($entry['recipientEmail']);
        self::assertSame($recipient->getUsername(), $entry['recipientUsername']);
        self::assertSame(
            sprintf('Hidden Reader (@%s)', $recipient->getUsername()),
            $entry['recipientLabel']
        );
        self::assertSame($code, $entry['recipientUserCode']);

        // And the recipient still gets an ordinary invitation addressed to them.
        $this->loginAs($recipient);
        $received = $this->getJson('/api/shares/shared-with-me')['sharedWithMe'];
        self::assertCount(1, $received);
        self::assertSame(ComicShare::STATUS_PENDING, $received[0]['status']);
    }

    /**
     * Re-inviting reuses the row, so a relationship that began with a user code
     * can be reopened by somebody typing the address. Going on hiding it then
     * would withhold something the owner plainly already has.
     */
    public function testTypingTheAddressLaterStopsHidingIt(): void
    {
        $recipient = UserFactory::createOne(['email' => 'both-ways@example.com', 'name' => 'Both Ways'])->object();
        $this->loginAs($recipient);
        $code = $this->getJson('/api/shares/user-code')['userCode'];

        $owner = $this->createAndLoginUser(['email' => 'switcher@example.com']);
        $comic = ComicFactory::new()->ownedBy($owner)->create()->object();

        $this->postJson('/api/shares/invitations/bulk', [
            'comicIds' => [$comic->getId()],
            'userCode' => $code,
            'senderResponsibilityAccepted' => true,
        ]);
        self::assertResponseStatusCodeSame(201);

        $shareId = $this->getJson('/api/shares/shared-by-me')['sharedByMe'][0]['recipients'][0]['id'];
        $this->postJson(sprintf('/api/shares/%d/revoke', $shareId), []);
        self::assertResponseIsSuccessful();

        // The same person, reached the other way this time.
        $this->postJson('/api/shares/invitations/bulk', [
            'comicIds' => [$comic->getId()],
            'email' => 'both-ways@example.com',
            'senderResponsibilityAccepted' => true,
        ]);
        self::assertResponseStatusCodeSame(201);

        $entry = $this->getJson('/api/shares/shared-by-me')['sharedByMe'][0]['recipients'][0];
        self::assertSame('both-ways@example.com', $entry['recipientEmail']);
        self::assertNull($entry['recipientUserCode']);
        // Still named by username, because that is the public identity of a
        // registered account. What changed is that the address is no longer
        // withheld, not what the owner is asked to read.
        self::assertSame(
            sprintf('Both Ways (@%s)', $recipient->getUsername()),
            $entry['recipientLabel']
        );
    }

    public function testAContentCodeCannotCarryMoreComicsThanAGroupMay(): void
    {
        $owner = $this->createAndLoginUser(['email' => 'oversized@example.com']);
        $comic = ComicFactory::new()->ownedBy($owner)->create()->object();

        $this->postJson('/api/shares/group-codes', [
            'comicIds' => array_fill(0, 500, (int) $comic->getId()),
            'maxUses' => 1,
            'senderResponsibilityAccepted' => true,
        ]);

        // Refused on the raw count, before any of those ids is looked up.
        self::assertResponseStatusCodeSame(400);
        self::assertSame([], $this->getJson('/api/shares/content-codes')['codes']);
    }

    public function testRecentRecipientsListACodeRecipientWithoutTheirAddress(): void
    {
        $recipient = UserFactory::createOne(['email' => 'quiet@example.com', 'name' => 'Quiet Reader'])->object();
        $this->loginAs($recipient);
        $code = $this->getJson('/api/shares/user-code')['userCode'];

        $owner = $this->createAndLoginUser(['email' => 'reuser@example.com']);
        $comic = ComicFactory::new()->ownedBy($owner)->create()->object();

        $this->postJson('/api/shares/invitations/bulk', [
            'comicIds' => [$comic->getId()],
            'userCode' => $code,
            'senderResponsibilityAccepted' => true,
        ]);
        self::assertResponseStatusCodeSame(201);

        $recipients = $this->getJson('/api/shares/recent-recipients')['recipients'];
        $body = (string) $this->browser()->getResponse()->getContent();

        self::assertStringNotContainsString('quiet@example.com', $body);
        self::assertCount(1, $recipients);
        self::assertNull($recipients[0]['email']);
        self::assertSame($recipient->getUsername(), $recipients[0]['username']);
        self::assertSame($code, $recipients[0]['userCode']);
        self::assertSame(sprintf('Quiet Reader (@%s)', $recipient->getUsername()), $recipients[0]['label']);
    }

    public function testAComicCodeIsShownOnceAndCountsDownAsItIsUsed(): void
    {
        $owner = $this->createAndLoginUser(['email' => 'claim-owner@example.com', 'name' => 'Claim Owner']);
        $comic = ComicFactory::new()->ownedBy($owner)->create(['title' => 'Claimable'])->object();

        $created = $this->postJson('/api/shares/comic-codes', [
            'comicIds' => [$comic->getId()],
            'maxUses' => 2,
            'senderResponsibilityAccepted' => true,
        ]);

        self::assertResponseStatusCodeSame(201);
        $code = $created['code'];
        self::assertStringStartsWith('C-', $code);
        self::assertMatchesRegularExpression(self::CODE_PATTERN, $code);
        self::assertSame(2, $created['contentCode']['usesRemaining']);
        self::assertSame('C', $created['contentCode']['type']);

        // Listing never gives the code back; revealing it is a separate,
        // owner-only request.
        $listed = $this->getJson('/api/shares/content-codes')['codes'];
        self::assertCount(1, $listed);
        self::assertArrayNotHasKey('code', $listed[0]);
        self::assertStringNotContainsString(
            str_replace('-', '', $code),
            (string) $this->browser()->getResponse()->getContent()
        );

        $first = $this->createAndLoginUser(['email' => 'first-claimer@example.com']);
        $claim = $this->postJson('/api/shares/content-codes/redeem', ['code' => $code]);
        self::assertResponseIsSuccessful();
        self::assertSame(1, $claim['claimed']);
        self::assertSame('C', $claim['type']);
        self::assertStringStartsWith('Claim Owner (@', $claim['ownerLabel']);
        self::assertSame(['claimed'], array_column($claim['results'], 'status'));

        // Redeeming is the recipient's own act, so an ordinary comic lands in
        // their collection rather than waiting to be accepted again.
        $received = $this->getJson('/api/shares/shared-with-me')['sharedWithMe'];
        self::assertSame(ComicShare::STATUS_ACCEPTED, $received[0]['status']);

        $this->createAndLoginUser(['email' => 'second-claimer@example.com']);
        $this->postJson('/api/shares/content-codes/redeem', ['code' => $code]);
        self::assertResponseIsSuccessful();

        // Two uses, two claimers, and then it is spent.
        $this->createAndLoginUser(['email' => 'third-claimer@example.com']);
        $payload = $this->postJson('/api/shares/content-codes/redeem', ['code' => $code]);
        self::assertResponseStatusCodeSame(404);
        self::assertStringContainsString('unavailable or no longer valid', $payload['message']);

        self::assertNotNull($first->getId());
    }

    public function testAnExhaustedCodeIsIndistinguishableFromOneThatNeverExisted(): void
    {
        $owner = $this->createAndLoginUser(['email' => 'used-up@example.com']);
        $comic = ComicFactory::new()->ownedBy($owner)->create()->object();
        $code = $this->postJson('/api/shares/comic-codes', [
            'comicIds' => [$comic->getId()],
            'maxUses' => 1,
            'senderResponsibilityAccepted' => true,
        ])['code'];

        $this->createAndLoginUser(['email' => 'claimer@example.com']);
        $this->postJson('/api/shares/content-codes/redeem', ['code' => $code]);
        self::assertResponseIsSuccessful();

        // Somebody who arrived too late. Exhaustion is about them, not about
        // the account that spent the use — see the replay test below.
        $this->createAndLoginUser(['email' => 'too-late@example.com']);
        $spent = $this->postJson('/api/shares/content-codes/redeem', ['code' => $code]);
        $spentStatus = $this->browser()->getResponse()->getStatusCode();

        $imaginary = $this->postJson('/api/shares/content-codes/redeem', ['code' => 'C-ZZZZ-ZZZZ-ZZZZ']);

        self::assertSame(404, $spentStatus);
        self::assertSame($spentStatus, $this->browser()->getResponse()->getStatusCode());
        self::assertSame($spent['message'], $imaginary['message']);
    }

    /**
     * A one-use code, submitted twice by the account that used it.
     *
     * The second submission is the same person pressing the button again, not
     * a second claim, so it replays what they already hold. Refusing it — which
     * is what judging the code before looking for their redemption does — hands
     * a 404 to the one person who definitely redeemed it successfully.
     */
    public function testTheAccountThatSpentTheLastUseCanStillReplayIt(): void
    {
        $owner = $this->createAndLoginUser(['email' => 'single-use@example.com']);
        $comic = ComicFactory::new()->ownedBy($owner)->create()->object();
        $code = $this->postJson('/api/shares/comic-codes', [
            'comicIds' => [$comic->getId()],
            'maxUses' => 1,
            'senderResponsibilityAccepted' => true,
        ])['code'];

        $this->createAndLoginUser(['email' => 'double-clicker@example.com']);

        $first = $this->postJson('/api/shares/content-codes/redeem', ['code' => $code]);
        self::assertResponseIsSuccessful();
        self::assertSame(1, $first['claimed']);
        self::assertFalse($first['alreadyRedeemed']);

        $again = $this->postJson('/api/shares/content-codes/redeem', ['code' => $code]);
        self::assertResponseIsSuccessful();
        self::assertTrue($again['alreadyRedeemed']);
        // Re-reported, not re-granted: the comic they already hold, and no
        // second use taken from an offer that had none left to give.
        self::assertSame(0, $again['claimed']);
        self::assertSame(1, $again['alreadyHeld']);

        $this->loginAs($owner);
        $codes = $this->getJson('/api/shares/content-codes')['codes'];
        self::assertSame(1, $codes[0]['timesUsed']);
        self::assertSame(0, $codes[0]['usesRemaining']);
    }

    public function testAnExpiredContentCodeIsRefused(): void
    {
        $owner = $this->createAndLoginUser(['email' => 'stale-code@example.com']);
        $comic = ComicFactory::new()->ownedBy($owner)->create()->object();
        $code = $this->postJson('/api/shares/comic-codes', [
            'comicIds' => [$comic->getId()],
            'maxUses' => 5,
            'senderResponsibilityAccepted' => true,
        ])['code'];

        // The lifetime is the whole guarantee: a code pasted into a group chat
        // is out of its owner's hands the moment it is sent.
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $stored = $entityManager->getRepository(ShareClaimCode::class)->findOneBy([]);
        $entityManager->getConnection()->executeStatement(
            'UPDATE share_claim_code SET expires_at = :expired WHERE id = :id',
            [
                'expired' => (new \DateTimeImmutable('-1 minute'))->format('Y-m-d H:i:s'),
                'id' => $stored->getId(),
            ]
        );
        $entityManager->clear();

        $this->createAndLoginUser(['email' => 'late@example.com']);
        $this->postJson('/api/shares/content-codes/redeem', ['code' => $code]);

        self::assertResponseStatusCodeSame(404);
    }

    public function testRedeemingLeavesAnExplicitComicBehindTheAgeGate(): void
    {
        $owner = $this->createAndLoginUser(['email' => 'explicit-owner@example.com']);
        $ordinary = ComicFactory::new()->ownedBy($owner)->create(['title' => 'All Ages'])->object();
        $explicit = ComicFactory::new()->ownedBy($owner)
            ->create(['title' => 'Adults Only', 'explicitContent' => true])
            ->object();

        // Two comics, so this is a group — and a group may mix the two, with
        // the ordinary half arriving and the explicit half waiting on the gate.
        $code = $this->postJson('/api/shares/group-codes', [
            'comicIds' => [$ordinary->getId(), $explicit->getId()],
            'maxUses' => 1,
            'senderResponsibilityAccepted' => true,
        ])['code'];

        $this->createAndLoginUser(['email' => 'young@example.com']);
        $payload = $this->postJson('/api/shares/content-codes/redeem', ['code' => $code]);

        self::assertResponseIsSuccessful();
        $byStatus = array_column($payload['results'], 'status');
        self::assertEqualsCanonicalizing(['claimed', 'awaiting_age_confirmation'], $byStatus);

        // Redeeming stands in for accepting, but never for declaring an age.
        $received = $this->getJson('/api/shares/shared-with-me')['sharedWithMe'];
        $gated = null;
        foreach ($received as $share) {
            if ($share['requiresAdultConfirmation'] === true) {
                $gated = $share;
            }
        }

        self::assertNotNull($gated, 'The explicit comic should still be gated.');
        self::assertSame(ComicShare::STATUS_PENDING, $gated['status']);
        // And it reveals nothing about itself while it is gated.
        self::assertNull($gated['comicTitle']);
        self::assertStringNotContainsString(
            'Adults Only',
            (string) $this->browser()->getResponse()->getContent()
        );
    }

    public function testAnOwnerCannotRedeemTheirOwnContentCode(): void
    {
        $owner = $this->createAndLoginUser(['email' => 'self-claim@example.com']);
        $comic = ComicFactory::new()->ownedBy($owner)->create()->object();
        $code = $this->postJson('/api/shares/comic-codes', [
            'comicIds' => [$comic->getId()],
            'maxUses' => 1,
            'senderResponsibilityAccepted' => true,
        ])['code'];

        $payload = $this->postJson('/api/shares/content-codes/redeem', ['code' => $code]);

        self::assertResponseStatusCodeSame(409);
        self::assertStringContainsString('your own', $payload['message']);
    }

    public function testAContentCodeCannotCarrySomebodyElsesComic(): void
    {
        $stranger = UserFactory::createOne(['email' => 'stranger@example.com'])->object();
        $theirs = ComicFactory::new()->ownedBy($stranger)->create()->object();

        $this->createAndLoginUser(['email' => 'grabby@example.com']);
        $this->postJson('/api/shares/comic-codes', [
            'comicIds' => [$theirs->getId()],
            'maxUses' => 1,
            'senderResponsibilityAccepted' => true,
        ]);

        self::assertResponseStatusCodeSame(403);
        self::assertSame([], $this->getJson('/api/shares/content-codes')['codes']);
    }

    public function testAContentCodeStillRequiresTheSenderResponsibilityAcknowledgement(): void
    {
        $owner = $this->createAndLoginUser(['email' => 'unacknowledged@example.com']);
        $comic = ComicFactory::new()->ownedBy($owner)->create()->object();

        $payload = $this->postJson('/api/shares/comic-codes', [
            'comicIds' => [$comic->getId()],
            'maxUses' => 1,
            'senderResponsibilityAccepted' => false,
        ]);

        self::assertResponseStatusCodeSame(400);
        self::assertStringContainsString('acknowledge responsibility', $payload['message']);
        self::assertSame([], $this->getJson('/api/shares/content-codes')['codes']);
    }

    public function testAContentCodeMustBeUsableBetweenOneAndTenTimes(): void
    {
        $owner = $this->createAndLoginUser(['email' => 'greedy-uses@example.com']);
        $comic = ComicFactory::new()->ownedBy($owner)->create()->object();

        foreach ([0, ShareClaimCode::MAX_USES + 1, -3] as $uses) {
            $this->postJson('/api/shares/comic-codes', [
                'comicIds' => [$comic->getId()],
                'maxUses' => $uses,
                'senderResponsibilityAccepted' => true,
            ]);
            self::assertResponseStatusCodeSame(400, sprintf('%d uses should be refused.', $uses));
        }

        $this->postJson('/api/shares/comic-codes', [
            'comicIds' => [$comic->getId()],
            'maxUses' => ShareClaimCode::MAX_USES,
            'senderResponsibilityAccepted' => true,
        ]);
        self::assertResponseStatusCodeSame(201);
    }

    public function testWithdrawingACodeStopsItWithoutTouchingWhatItAlreadyGaveOut(): void
    {
        $owner = $this->createAndLoginUser(['email' => 'withdrawer@example.com']);
        $comic = ComicFactory::new()->ownedBy($owner)->create()->object();
        $created = $this->postJson('/api/shares/comic-codes', [
            'comicIds' => [$comic->getId()],
            'maxUses' => 5,
            'senderResponsibilityAccepted' => true,
        ]);
        $code = $created['code'];

        $this->createAndLoginUser(['email' => 'early-bird@example.com']);
        $this->postJson('/api/shares/content-codes/redeem', ['code' => $code]);
        self::assertResponseIsSuccessful();

        $this->loginAs($owner);
        $this->deleteJson('/api/shares/content-codes/' . $created['contentCode']['id']);
        self::assertResponseIsSuccessful();

        // The relationship it produced is an ordinary share now, revoked from
        // the Sharing page like any other rather than by the code going away.
        $sharedByMe = $this->getJson('/api/shares/shared-by-me')['sharedByMe'];
        self::assertCount(1, $sharedByMe);
        self::assertSame(ComicShare::STATUS_ACCEPTED, $sharedByMe[0]['recipients'][0]['status']);

        $this->createAndLoginUser(['email' => 'too-late@example.com']);
        $this->postJson('/api/shares/content-codes/redeem', ['code' => $code]);
        self::assertResponseStatusCodeSame(404);
    }

    /**
     * A code the owner has changed their mind about must stop working straight
     * away, not in whatever is left of its lifetime.
     */
    public function testWithdrawingACodeTakesEffectImmediately(): void
    {
        $owner = $this->createAndLoginUser(['email' => 'quick-change@example.com']);
        $comic = ComicFactory::new()->ownedBy($owner)->create()->object();
        $created = $this->postJson('/api/shares/comic-codes', [
            'comicIds' => [$comic->getId()],
            'maxUses' => 10,
            'senderResponsibilityAccepted' => true,
        ]);

        self::assertTrue($created['contentCode']['isRedeemable']);
        self::assertFalse($created['contentCode']['isExpired']);

        $this->deleteJson('/api/shares/content-codes/' . $created['contentCode']['id']);
        self::assertResponseIsSuccessful();

        // Still listed — dead codes are kept so the owner can see what happened
        // to them — but plainly dead, and with no uses spent.
        $listed = $this->getJson('/api/shares/content-codes')['codes'];
        self::assertCount(1, $listed);
        self::assertTrue($listed[0]['isRevoked']);
        self::assertFalse($listed[0]['isRedeemable']);
        self::assertSame('withdrawn', $listed[0]['deadReason']);
        self::assertSame(0, $listed[0]['timesUsed']);

        $this->createAndLoginUser(['email' => 'shut-out@example.com']);
        $this->postJson('/api/shares/content-codes/redeem', ['code' => $created['code']]);
        self::assertResponseStatusCodeSame(404);
    }

    public function testAnotherOwnerCannotWithdrawSomebodyElsesCode(): void
    {
        $owner = $this->createAndLoginUser(['email' => 'code-owner@example.com']);
        $comic = ComicFactory::new()->ownedBy($owner)->create()->object();
        $created = $this->postJson('/api/shares/comic-codes', [
            'comicIds' => [$comic->getId()],
            'maxUses' => 3,
            'senderResponsibilityAccepted' => true,
        ]);

        $this->createAndLoginUser(['email' => 'meddler@example.com']);
        // Reported as missing rather than forbidden, so an id cannot be probed
        // for whether it belongs to somebody's code.
        $this->deleteJson('/api/shares/content-codes/' . $created['contentCode']['id']);
        self::assertResponseStatusCodeSame(404);

        $this->createAndLoginUser(['email' => 'still-welcome@example.com']);
        $this->postJson('/api/shares/content-codes/redeem', ['code' => $created['code']]);
        self::assertResponseIsSuccessful();
    }

    public function testEveryIssuedTokenIsUniqueAcrossEveryKind(): void
    {
        $owner = $this->createAndLoginUser(['email' => 'many-codes@example.com']);
        $comic = ComicFactory::new()->ownedBy($owner)->create()->object();

        // Compared without their prefixes. The prefix already makes a user code
        // and a comic code different strings; what is asserted here is the
        // stronger property behind it — that one visible *token* never means two
        // things, which is what makes a code safe to read aloud.
        $tokens = [substr($this->getJson('/api/shares/user-code')['userCode'], 2)];

        for ($i = 0; $i < 8; ++$i) {
            $tokens[] = substr($this->postJson('/api/shares/comic-codes', [
                'comicIds' => [$comic->getId()],
                'maxUses' => 1,
                'senderResponsibilityAccepted' => true,
            ])['code'], 2);
            self::assertResponseStatusCodeSame(201);
        }

        self::assertCount(count($tokens), array_unique($tokens));

        // And the unique index is the authority behind the check, not just the
        // check itself.
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $hashes = $entityManager->getConnection()
            ->fetchFirstColumn('SELECT code_hash FROM share_claim_code');
        self::assertSame(count($hashes), count(array_unique($hashes)));
    }

    /**
     * Dead codes are kept for a month so the owner can still see how many
     * people took them up, then swept.
     */
    public function testTheCleanupKeepsADeadCodeForAMonthAndThenRemovesIt(): void
    {
        $owner = $this->createAndLoginUser(['email' => 'housekeeping@example.com']);
        $comic = ComicFactory::new()->ownedBy($owner)->create()->object();
        $this->postJson('/api/shares/comic-codes', [
            'comicIds' => [$comic->getId()],
            'maxUses' => 1,
            'senderResponsibilityAccepted' => true,
        ]);

        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $repository = $entityManager->getRepository(ShareClaimCode::class);
        $codeId = $repository->findOneBy([])->getId();

        $expireAt = static function (string $modifier) use ($entityManager, $codeId): void {
            $entityManager->getConnection()->executeStatement(
                'UPDATE share_claim_code SET expires_at = :when WHERE id = :id',
                ['when' => (new \DateTimeImmutable($modifier))->format('Y-m-d H:i:s'), 'id' => $codeId]
            );
            $entityManager->clear();
        };

        // Expired yesterday: dead, but still the owner's record of what they
        // handed out.
        $expireAt('-1 day');
        self::assertSame([], $repository->findDeletable(new \DateTimeImmutable(), 100));
        self::assertCount(1, $this->getJson('/api/shares/content-codes')['codes']);

        // A month and a day later there is nothing left to look at.
        $expireAt('-31 days');
        $deletable = $repository->findDeletable(new \DateTimeImmutable(), 100);
        self::assertCount(1, $deletable);

        $entityManager->remove($deletable[0]);
        $entityManager->flush();

        // The comic is untouched by the sweep — a code is a way in, never the
        // access itself.
        self::assertSame([], $this->getJson('/api/shares/content-codes')['codes']);
        self::assertNotNull($entityManager->getRepository(Comic::class)->find($comic->getId()));
    }

    /* ---------------------------------------------------------------------- */
    /* Rotation                                                               */
    /* ---------------------------------------------------------------------- */

    public function testRotatingRetiresTheOldCodeAndIssuesANewOne(): void
    {
        $recipient = $this->createAndLoginUser(['email' => 'rotator@example.com', 'name' => 'Rotator']);
        $original = $this->getJson('/api/shares/user-code')['userCode'];

        $rotated = $this->postJson('/api/shares/user-code/rotate', []);
        self::assertResponseIsSuccessful();
        self::assertNotSame($original, $rotated['userCode']);
        self::assertSame('Rotator', $rotated['name']);

        // Read back the same new one, not a third.
        self::assertSame($rotated['userCode'], $this->getJson('/api/shares/user-code')['userCode']);

        $this->createAndLoginUser(['email' => 'holder-of-old@example.com']);
        // The old code is gone for everybody who was given it, which is the
        // entire point.
        $this->postJson('/api/shares/user-code/resolve', ['userCode' => $original]);
        self::assertResponseStatusCodeSame(404);

        $resolved = $this->postJson('/api/shares/user-code/resolve', ['userCode' => $rotated['userCode']]);
        self::assertResponseIsSuccessful();
        self::assertSame('Rotator', $resolved['recipient']['name']);
        self::assertNotNull($recipient->getId());
    }

    /**
     * A username is the durable half of the identity: retiring the code that
     * points at somebody must not change who they are.
     */
    public function testRotatingLeavesTheUsernameAlone(): void
    {
        $this->createAndLoginUser(['email' => 'renamer@example.com']);
        $before = $this->getJson('/api/shares/user-code');

        $after = $this->postJson('/api/shares/user-code/rotate', []);

        self::assertNotSame($before['userCode'], $after['userCode']);
        self::assertSame($before['username'], $after['username']);
    }

    public function testRotatingLeavesExistingSharesUntouched(): void
    {
        $recipient = UserFactory::createOne(['email' => 'keeps-comics@example.com', 'name' => 'Keeper'])->object();
        $this->loginAs($recipient);
        $original = $this->getJson('/api/shares/user-code')['userCode'];

        $owner = $this->createAndLoginUser(['email' => 'gave-comics@example.com']);
        $comic = ComicFactory::new()->ownedBy($owner)->create(['title' => 'Still Mine'])->object();
        $this->postJson('/api/shares/invitations/bulk', [
            'comicIds' => [$comic->getId()],
            'userCode' => $original,
            'senderResponsibilityAccepted' => true,
        ]);
        self::assertResponseStatusCodeSame(201);

        $this->loginAs($recipient);
        $newCode = $this->postJson('/api/shares/user-code/rotate', [])['userCode'];

        // Rotation replaces an address, not a relationship.
        $received = $this->getJson('/api/shares/shared-with-me')['sharedWithMe'];
        self::assertCount(1, $received);
        self::assertSame(ComicShare::STATUS_PENDING, $received[0]['status']);

        // And the sender is offered the new code, never the retired one — a
        // stale snapshot in the picker would put the withdrawn code straight
        // back into circulation.
        $this->loginAs($owner);
        $entry = $this->getJson('/api/shares/shared-by-me')['sharedByMe'][0]['recipients'][0];
        self::assertSame($newCode, $entry['recipientUserCode']);
        self::assertNull($entry['recipientEmail']);

        $recipients = $this->getJson('/api/shares/recent-recipients')['recipients'];
        self::assertSame([$newCode], array_column($recipients, 'userCode'));
        self::assertStringNotContainsString(
            str_replace('-', '', $original),
            str_replace('-', '', (string) $this->browser()->getResponse()->getContent())
        );
    }

    public function testAnAdminCanRotateSomebodyElsesCodeWithoutSeeingIt(): void
    {
        $target = UserFactory::createOne(['email' => 'needs-help@example.com'])->object();
        $this->loginAs($target);
        $original = $this->getJson('/api/shares/user-code')['userCode'];

        $this->createAndLoginAdmin(['email' => 'support@example.com']);
        $payload = $this->postJson(sprintf('/api/users/%d/user-code/rotate', $target->getId()), []);

        self::assertResponseIsSuccessful();
        // Support has no reason to hold somebody's contact handle; the user
        // reads the new one off their own page.
        self::assertArrayNotHasKey('userCode', $payload);
        self::assertStringNotContainsString(
            str_replace('-', '', $original),
            str_replace('-', '', (string) $this->browser()->getResponse()->getContent())
        );

        $this->loginAs($target);
        self::assertNotSame($original, $this->getJson('/api/shares/user-code')['userCode']);
    }

    public function testAnOrdinaryUserCannotRotateSomebodyElsesCode(): void
    {
        $target = UserFactory::createOne(['email' => 'not-yours@example.com'])->object();
        $this->loginAs($target);
        $original = $this->getJson('/api/shares/user-code')['userCode'];

        $this->createAndLoginUser(['email' => 'meddling-user@example.com']);
        $this->postJson(sprintf('/api/users/%d/user-code/rotate', $target->getId()), []);
        self::assertResponseStatusCodeSame(403);

        $this->loginAs($target);
        self::assertSame($original, $this->getJson('/api/shares/user-code')['userCode']);
    }

    public function testRotationIsRateLimited(): void
    {
        $this->createAndLoginUser(['email' => 'spinner@example.com']);
        $this->getJson('/api/shares/user-code');

        // Rotated until it is refused rather than exactly N times: the limiter
        // is cache-backed while the database rolls back between tests, so a
        // user id can arrive at a key an earlier test already spent from. What
        // matters is that the allowance is finite and says so.
        $refused = null;
        for ($attempt = 0; $attempt < 6 && $refused === null; ++$attempt) {
            $payload = $this->postJson('/api/shares/user-code/rotate', []);

            if ($this->browser()->getResponse()->getStatusCode() === 429) {
                $refused = $payload;
            }
        }

        self::assertNotNull($refused, 'Rotation should run out of allowance.');
        self::assertStringContainsString('too many times', $refused['message']);
    }

    /* ---------------------------------------------------------------------- */
    /* Redemption identity                                                    */
    /* ---------------------------------------------------------------------- */

    /**
     * "Ten uses" has to mean ten people. Charging per request lets one
     * recipient exhaust an offer made to everybody else.
     */
    public function testOneAccountSpendsAtMostOneUseHoweverOftenItRedeems(): void
    {
        $owner = $this->createAndLoginUser(['email' => 'ten-uses@example.com']);
        $comic = ComicFactory::new()->ownedBy($owner)->create()->object();
        $created = $this->postJson('/api/shares/comic-codes', [
            'comicIds' => [$comic->getId()],
            'maxUses' => 10,
            'senderResponsibilityAccepted' => true,
        ]);
        $code = $created['code'];

        $this->createAndLoginUser(['email' => 'eager@example.com']);
        for ($i = 0; $i < 6; ++$i) {
            $payload = $this->postJson('/api/shares/content-codes/redeem', ['code' => $code]);
            self::assertResponseIsSuccessful();
            if ($i > 0) {
                self::assertTrue($payload['alreadyRedeemed'], 'A repeat redemption should say so.');
                self::assertSame(1, $payload['alreadyHeld'], 'A repeat reports what they already hold.');
            }
        }

        $this->loginAs($owner);
        $listed = $this->getJson('/api/shares/content-codes')['codes'][0];
        self::assertSame(1, $listed['timesUsed'], 'One account is one use, however many requests.');
        self::assertSame(9, $listed['usesRemaining']);
    }

    public function testDistinctAccountsEachSpendOneUseUntilTheCodeRunsOut(): void
    {
        $owner = $this->createAndLoginUser(['email' => 'party@example.com']);
        $comic = ComicFactory::new()->ownedBy($owner)->create()->object();
        $code = $this->postJson('/api/shares/comic-codes', [
            'comicIds' => [$comic->getId()],
            'maxUses' => 3,
            'senderResponsibilityAccepted' => true,
        ])['code'];

        for ($i = 0; $i < 3; ++$i) {
            $this->createAndLoginUser(['email' => sprintf('guest%d@example.com', $i)]);
            $this->postJson('/api/shares/content-codes/redeem', ['code' => $code]);
            self::assertResponseIsSuccessful(sprintf('Guest %d should be able to claim.', $i));
        }

        $this->createAndLoginUser(['email' => 'fourth@example.com']);
        $this->postJson('/api/shares/content-codes/redeem', ['code' => $code]);
        self::assertResponseStatusCodeSame(404);

        $this->loginAs($owner);
        self::assertSame(3, $this->getJson('/api/shares/content-codes')['codes'][0]['timesUsed']);
    }

    /**
     * The canonical evidence of the sender's acknowledgement has to say when
     * the sender acknowledged, not when somebody else consumed the code.
     */
    public function testAClaimedShareInheritsTheOwnersAcknowledgementTimestamp(): void
    {
        $owner = $this->createAndLoginUser(['email' => 'acknowledger@example.com']);
        $comic = ComicFactory::new()->ownedBy($owner)->create()->object();
        $code = $this->postJson('/api/shares/comic-codes', [
            'comicIds' => [$comic->getId()],
            'maxUses' => 1,
            'senderResponsibilityAccepted' => true,
        ])['code'];

        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $contentCode = $entityManager->getRepository(ShareClaimCode::class)->findOneBy([]);
        $acknowledgedAt = $contentCode->getSenderResponsibilityAcceptedAt();

        // Redeemed later, by somebody the owner was not present for.
        $entityManager->getConnection()->executeStatement(
            'UPDATE share_claim_code SET sender_responsibility_accepted_at = :then, created_at = :then WHERE id = :id',
            [
                'then' => $acknowledgedAt->modify('-8 hours')->format('Y-m-d H:i:s'),
                'id' => $contentCode->getId(),
            ]
        );
        $entityManager->clear();

        $expected = $acknowledgedAt->modify('-8 hours')->format('Y-m-d H:i:s');

        $this->createAndLoginUser(['email' => 'much-later@example.com']);
        $this->postJson('/api/shares/content-codes/redeem', ['code' => $code]);
        self::assertResponseIsSuccessful();

        $share = $entityManager->getRepository(ComicShare::class)->findOneBy([
            'recipientEmailNormalized' => 'much-later@example.com',
        ]);

        self::assertSame(
            $expected,
            $share->getSenderResponsibilityAcceptedAt()?->format('Y-m-d H:i:s'),
            'The share must record when the owner acknowledged, not when the code was redeemed.'
        );
    }

    public function testEveryCodeEndpointRequiresAuthentication(): void
    {
        $accept = ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json'];

        $this->browser()->request('GET', '/api/shares/user-code', [], [], $accept);
        self::assertResponseStatusCodeSame(401);

        $this->browser()->request('POST', '/api/shares/user-code/resolve', [], [], $accept, '{"userCode":"U-AAAA-AAAA-AAAA"}');
        self::assertResponseStatusCodeSame(401);

        $this->browser()->request('GET', '/api/shares/content-codes', [], [], $accept);
        self::assertResponseStatusCodeSame(401);

        $this->browser()->request('POST', '/api/shares/content-codes/redeem', [], [], $accept, '{"code":"C-AAAA-AAAA-AAAA"}');
        self::assertResponseStatusCodeSame(401);

        // Refused before the id is looked up, so this needs no code to exist.
        $this->browser()->request('GET', '/api/shares/content-codes/1/reveal', [], [], $accept);
        self::assertResponseStatusCodeSame(401);

        $this->browser()->request('POST', '/api/users/resolve-username', [], [], $accept, '{"username":"SomeoneElse"}');
        self::assertResponseStatusCodeSame(401);
    }
}
