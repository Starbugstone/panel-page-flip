<?php

namespace App\Tests\Functional\Controller;

use App\Entity\Comic;
use App\Entity\ComicShare;
use App\Entity\ShareClaimCode;
use App\Entity\User;
use App\Service\SharingCodeFormat;
use App\Tests\Factory\ComicFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Functional\AbstractApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Sharing codes, in both directions.
 *
 * A **receiver code** is permanent, belongs to the person who wants to be
 * shared with, and grants nothing: it only says who an invitation should be
 * addressed to, so the sender never has to be told an email address. A **claim
 * code** goes the other way — an owner hands it out and whoever redeems it gets
 * the comics behind it — so it is disposable, short-lived and counted down.
 *
 * The tests here are mostly about what a code must *not* do: name an address,
 * survive being used up, outlive a day, or answer differently for a code that
 * never existed than for one that has run out.
 */
final class SharingCodeControllerTest extends AbstractApiTestCase
{
    public function testAReceiverCodeIsIssuedOnceAndNeverChanges(): void
    {
        $user = $this->createAndLoginUser(['email' => 'stable@example.com', 'name' => 'Stable Reader']);

        $first = $this->getJson('/api/shares/my-code');
        self::assertResponseIsSuccessful();
        self::assertSame('Stable Reader', $first['name']);
        self::assertMatchesRegularExpression('/^[0-9A-Z]{4}-[0-9A-Z]{4}-[0-9A-Z]{4}$/', $first['sharingCode']);

        // Asked again, and again after the account has been reloaded from the
        // database: everybody who was ever given this code is holding it.
        self::assertSame($first['sharingCode'], $this->getJson('/api/shares/my-code')['sharingCode']);

        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $stored = $entityManager->getRepository(User::class)->find($user->getId());
        self::assertSame(
            $first['sharingCode'],
            SharingCodeFormat::forDisplay((string) $stored->getSharingCode())
        );
    }

    public function testResolvingACodeRevealsANameAndNothingElse(): void
    {
        $recipient = UserFactory::createOne([
            'email' => 'private-address@example.com',
            'name' => 'Jane Reader',
        ])->object();
        $this->loginAs($recipient);
        $code = $this->getJson('/api/shares/my-code')['sharingCode'];

        $this->createAndLoginUser(['email' => 'sender@example.com']);
        $payload = $this->postJson('/api/shares/resolve-code', ['sharingCode' => $code]);

        self::assertResponseIsSuccessful();
        self::assertSame('Jane Reader', $payload['recipient']['name']);
        // The address is the whole thing a receiver code exists to withhold.
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
    public function testAnUnknownCodeIsAnswerdTheSameWayHoweverItIsWrong(): void
    {
        $this->createAndLoginUser(['email' => 'prober@example.com']);

        $answers = [];
        foreach (['not-a-code', 'ZZZZ-ZZZZ-ZZZZ', '0000-0000-0000'] as $attempt) {
            $payload = $this->postJson('/api/shares/resolve-code', ['sharingCode' => $attempt]);
            $answers[] = [$this->browser()->getResponse()->getStatusCode(), $payload['message']];
        }

        self::assertCount(1, array_unique($answers, SORT_REGULAR));
        self::assertSame(404, $answers[0][0]);
    }

    public function testSharingByReceiverCodeNeverShowsTheSenderTheAddress(): void
    {
        $recipient = UserFactory::createOne([
            'email' => 'hidden@example.com',
            'name' => 'Hidden Reader',
        ])->object();
        $this->loginAs($recipient);
        $code = $this->getJson('/api/shares/my-code')['sharingCode'];

        $owner = $this->createAndLoginUser(['email' => 'code-sender@example.com']);
        $comic = ComicFactory::new()->ownedBy($owner)->create(['title' => 'Shared By Code'])->object();

        $payload = $this->postJson('/api/shares/invitations/bulk', [
            'comicIds' => [$comic->getId()],
            'sharingCode' => $code,
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
        self::assertSame('Hidden Reader', $entry['recipientLabel']);
        self::assertSame($code, $entry['recipientSharingCode']);

        // And the recipient still gets an ordinary invitation addressed to them.
        $this->loginAs($recipient);
        $received = $this->getJson('/api/shares/shared-with-me')['sharedWithMe'];
        self::assertCount(1, $received);
        self::assertSame(ComicShare::STATUS_PENDING, $received[0]['status']);
    }

    /**
     * Re-inviting reuses the row, so a relationship that began with a receiver
     * code can be reopened by somebody typing the address. Going on hiding it
     * then would withhold something the owner plainly already has.
     */
    public function testTypingTheAddressLaterStopsHidingIt(): void
    {
        $recipient = UserFactory::createOne(['email' => 'both-ways@example.com', 'name' => 'Both Ways'])->object();
        $this->loginAs($recipient);
        $code = $this->getJson('/api/shares/my-code')['sharingCode'];

        $owner = $this->createAndLoginUser(['email' => 'switcher@example.com']);
        $comic = ComicFactory::new()->ownedBy($owner)->create()->object();

        $this->postJson('/api/shares/invitations/bulk', [
            'comicIds' => [$comic->getId()],
            'sharingCode' => $code,
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
        self::assertNull($entry['recipientSharingCode']);
        self::assertSame('both-ways@example.com', $entry['recipientLabel']);
    }

    public function testAClaimCodeCannotCarryMoreComicsThanOneActionMay(): void
    {
        $owner = $this->createAndLoginUser(['email' => 'oversized@example.com']);
        $comic = ComicFactory::new()->ownedBy($owner)->create()->object();

        $this->postJson('/api/shares/claim-codes', [
            'comicIds' => array_fill(0, 500, (int) $comic->getId()),
            'maxUses' => 1,
            'senderResponsibilityAccepted' => true,
        ]);

        // Refused on the raw count, before any of those ids is looked up.
        self::assertResponseStatusCodeSame(400);
        self::assertSame([], $this->getJson('/api/shares/claim-codes')['codes']);
    }

    public function testRecentRecipientsListACodeRecipientWithoutTheirAddress(): void
    {
        $recipient = UserFactory::createOne(['email' => 'quiet@example.com', 'name' => 'Quiet Reader'])->object();
        $this->loginAs($recipient);
        $code = $this->getJson('/api/shares/my-code')['sharingCode'];

        $owner = $this->createAndLoginUser(['email' => 'reuser@example.com']);
        $comic = ComicFactory::new()->ownedBy($owner)->create()->object();

        $this->postJson('/api/shares/invitations/bulk', [
            'comicIds' => [$comic->getId()],
            'sharingCode' => $code,
            'senderResponsibilityAccepted' => true,
        ]);
        self::assertResponseStatusCodeSame(201);

        $recipients = $this->getJson('/api/shares/recent-recipients')['recipients'];
        $body = (string) $this->browser()->getResponse()->getContent();

        self::assertStringNotContainsString('quiet@example.com', $body);
        self::assertCount(1, $recipients);
        self::assertNull($recipients[0]['email']);
        self::assertSame($code, $recipients[0]['sharingCode']);
        self::assertSame('Quiet Reader', $recipients[0]['label']);
    }

    public function testAClaimCodeIsShownOnceAndCountsDownAsItIsUsed(): void
    {
        $owner = $this->createAndLoginUser(['email' => 'claim-owner@example.com', 'name' => 'Claim Owner']);
        $comic = ComicFactory::new()->ownedBy($owner)->create(['title' => 'Claimable'])->object();

        $created = $this->postJson('/api/shares/claim-codes', [
            'comicIds' => [$comic->getId()],
            'maxUses' => 2,
            'senderResponsibilityAccepted' => true,
        ]);

        self::assertResponseStatusCodeSame(201);
        $code = $created['code'];
        self::assertMatchesRegularExpression('/^[0-9A-Z]{4}-[0-9A-Z]{4}-[0-9A-Z]{4}$/', $code);
        self::assertSame(2, $created['claimCode']['usesRemaining']);

        // Listing never gives the code back — only its hash is stored.
        $listed = $this->getJson('/api/shares/claim-codes')['codes'];
        self::assertCount(1, $listed);
        self::assertArrayNotHasKey('code', $listed[0]);
        self::assertStringNotContainsString(
            str_replace('-', '', $code),
            (string) $this->browser()->getResponse()->getContent()
        );

        $first = $this->createAndLoginUser(['email' => 'first-claimer@example.com']);
        $claim = $this->postJson('/api/shares/claim-codes/redeem', ['code' => $code]);
        self::assertResponseIsSuccessful();
        self::assertSame(1, $claim['claimed']);
        self::assertSame('Claim Owner', $claim['ownerName']);
        self::assertSame(['claimed'], array_column($claim['results'], 'status'));

        // Redeeming is the recipient's own act, so an ordinary comic lands in
        // their collection rather than waiting to be accepted again.
        $received = $this->getJson('/api/shares/shared-with-me')['sharedWithMe'];
        self::assertSame(ComicShare::STATUS_ACCEPTED, $received[0]['status']);

        $this->createAndLoginUser(['email' => 'second-claimer@example.com']);
        $this->postJson('/api/shares/claim-codes/redeem', ['code' => $code]);
        self::assertResponseIsSuccessful();

        // Two uses, two claimers, and then it is spent.
        $this->createAndLoginUser(['email' => 'third-claimer@example.com']);
        $payload = $this->postJson('/api/shares/claim-codes/redeem', ['code' => $code]);
        self::assertResponseStatusCodeSame(404);
        self::assertStringContainsString('not valid', $payload['message']);

        self::assertNotNull($first->getId());
    }

    public function testAnExhaustedCodeIsIndistinguishableFromOneThatNeverExisted(): void
    {
        $owner = $this->createAndLoginUser(['email' => 'used-up@example.com']);
        $comic = ComicFactory::new()->ownedBy($owner)->create()->object();
        $code = $this->postJson('/api/shares/claim-codes', [
            'comicIds' => [$comic->getId()],
            'maxUses' => 1,
            'senderResponsibilityAccepted' => true,
        ])['code'];

        $this->createAndLoginUser(['email' => 'claimer@example.com']);
        $this->postJson('/api/shares/claim-codes/redeem', ['code' => $code]);
        self::assertResponseIsSuccessful();

        $spent = $this->postJson('/api/shares/claim-codes/redeem', ['code' => $code]);
        $spentStatus = $this->browser()->getResponse()->getStatusCode();

        $imaginary = $this->postJson('/api/shares/claim-codes/redeem', ['code' => 'ZZZZ-ZZZZ-ZZZZ']);

        self::assertSame($spentStatus, $this->browser()->getResponse()->getStatusCode());
        self::assertSame($spent['message'], $imaginary['message']);
    }

    public function testAnExpiredClaimCodeIsRefused(): void
    {
        $owner = $this->createAndLoginUser(['email' => 'stale-code@example.com']);
        $comic = ComicFactory::new()->ownedBy($owner)->create()->object();
        $code = $this->postJson('/api/shares/claim-codes', [
            'comicIds' => [$comic->getId()],
            'maxUses' => 5,
            'senderResponsibilityAccepted' => true,
        ])['code'];

        // A day is the whole guarantee: a code pasted into a group chat is out
        // of its owner's hands the moment it is sent.
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
        $this->postJson('/api/shares/claim-codes/redeem', ['code' => $code]);

        self::assertResponseStatusCodeSame(404);
    }

    public function testRedeemingLeavesAnExplicitComicBehindTheAgeGate(): void
    {
        $owner = $this->createAndLoginUser(['email' => 'explicit-owner@example.com']);
        $ordinary = ComicFactory::new()->ownedBy($owner)->create(['title' => 'All Ages'])->object();
        $explicit = ComicFactory::new()->ownedBy($owner)
            ->create(['title' => 'Adults Only', 'explicitContent' => true])
            ->object();

        $code = $this->postJson('/api/shares/claim-codes', [
            'comicIds' => [$ordinary->getId(), $explicit->getId()],
            'maxUses' => 1,
            'senderResponsibilityAccepted' => true,
        ])['code'];

        $this->createAndLoginUser(['email' => 'young@example.com']);
        $payload = $this->postJson('/api/shares/claim-codes/redeem', ['code' => $code]);

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

    public function testAnOwnerCannotRedeemTheirOwnClaimCode(): void
    {
        $owner = $this->createAndLoginUser(['email' => 'self-claim@example.com']);
        $comic = ComicFactory::new()->ownedBy($owner)->create()->object();
        $code = $this->postJson('/api/shares/claim-codes', [
            'comicIds' => [$comic->getId()],
            'maxUses' => 1,
            'senderResponsibilityAccepted' => true,
        ])['code'];

        $payload = $this->postJson('/api/shares/claim-codes/redeem', ['code' => $code]);

        self::assertResponseStatusCodeSame(409);
        self::assertStringContainsString('your own', $payload['message']);
    }

    public function testAClaimCodeCannotCarrySomebodyElsesComic(): void
    {
        $stranger = UserFactory::createOne(['email' => 'stranger@example.com'])->object();
        $theirs = ComicFactory::new()->ownedBy($stranger)->create()->object();

        $this->createAndLoginUser(['email' => 'grabby@example.com']);
        $this->postJson('/api/shares/claim-codes', [
            'comicIds' => [$theirs->getId()],
            'maxUses' => 1,
            'senderResponsibilityAccepted' => true,
        ]);

        self::assertResponseStatusCodeSame(403);
        self::assertSame([], $this->getJson('/api/shares/claim-codes')['codes']);
    }

    public function testAClaimCodeStillRequiresTheSenderResponsibilityAcknowledgement(): void
    {
        $owner = $this->createAndLoginUser(['email' => 'unacknowledged@example.com']);
        $comic = ComicFactory::new()->ownedBy($owner)->create()->object();

        $payload = $this->postJson('/api/shares/claim-codes', [
            'comicIds' => [$comic->getId()],
            'maxUses' => 1,
            'senderResponsibilityAccepted' => false,
        ]);

        self::assertResponseStatusCodeSame(400);
        self::assertStringContainsString('acknowledge responsibility', $payload['message']);
        self::assertSame([], $this->getJson('/api/shares/claim-codes')['codes']);
    }

    public function testAClaimCodeMustBeUsableBetweenOneAndTenTimes(): void
    {
        $owner = $this->createAndLoginUser(['email' => 'greedy-uses@example.com']);
        $comic = ComicFactory::new()->ownedBy($owner)->create()->object();

        foreach ([0, ShareClaimCode::MAX_USES + 1, -3] as $uses) {
            $this->postJson('/api/shares/claim-codes', [
                'comicIds' => [$comic->getId()],
                'maxUses' => $uses,
                'senderResponsibilityAccepted' => true,
            ]);
            self::assertResponseStatusCodeSame(400, sprintf('%d uses should be refused.', $uses));
        }

        $this->postJson('/api/shares/claim-codes', [
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
        $created = $this->postJson('/api/shares/claim-codes', [
            'comicIds' => [$comic->getId()],
            'maxUses' => 5,
            'senderResponsibilityAccepted' => true,
        ]);
        $code = $created['code'];

        $this->createAndLoginUser(['email' => 'early-bird@example.com']);
        $this->postJson('/api/shares/claim-codes/redeem', ['code' => $code]);
        self::assertResponseIsSuccessful();

        $this->loginAs($owner);
        $this->deleteJson('/api/shares/claim-codes/' . $created['claimCode']['id']);
        self::assertResponseIsSuccessful();

        // The relationship it produced is an ordinary share now, revoked from
        // the Sharing page like any other rather than by the code going away.
        $sharedByMe = $this->getJson('/api/shares/shared-by-me')['sharedByMe'];
        self::assertCount(1, $sharedByMe);
        self::assertSame(ComicShare::STATUS_ACCEPTED, $sharedByMe[0]['recipients'][0]['status']);

        $this->createAndLoginUser(['email' => 'too-late@example.com']);
        $this->postJson('/api/shares/claim-codes/redeem', ['code' => $code]);
        self::assertResponseStatusCodeSame(404);
    }

    /**
     * A code the owner has changed their mind about must stop working straight
     * away, not in whatever is left of its day.
     */
    public function testWithdrawingACodeTakesEffectImmediately(): void
    {
        $owner = $this->createAndLoginUser(['email' => 'quick-change@example.com']);
        $comic = ComicFactory::new()->ownedBy($owner)->create()->object();
        $created = $this->postJson('/api/shares/claim-codes', [
            'comicIds' => [$comic->getId()],
            'maxUses' => 10,
            'senderResponsibilityAccepted' => true,
        ]);

        self::assertTrue($created['claimCode']['isRedeemable']);
        self::assertFalse($created['claimCode']['isExpired']);

        $this->deleteJson('/api/shares/claim-codes/' . $created['claimCode']['id']);
        self::assertResponseIsSuccessful();

        // Still listed — dead codes are kept so the owner can see what happened
        // to them — but plainly dead, and with no uses spent.
        $listed = $this->getJson('/api/shares/claim-codes')['codes'];
        self::assertCount(1, $listed);
        self::assertTrue($listed[0]['isRevoked']);
        self::assertFalse($listed[0]['isRedeemable']);
        self::assertSame('withdrawn', $listed[0]['deadReason']);
        self::assertSame(0, $listed[0]['timesUsed']);

        $this->createAndLoginUser(['email' => 'shut-out@example.com']);
        $this->postJson('/api/shares/claim-codes/redeem', ['code' => $created['code']]);
        self::assertResponseStatusCodeSame(404);
    }

    public function testAnotherOwnerCannotWithdrawSomebodyElsesCode(): void
    {
        $owner = $this->createAndLoginUser(['email' => 'code-owner@example.com']);
        $comic = ComicFactory::new()->ownedBy($owner)->create()->object();
        $created = $this->postJson('/api/shares/claim-codes', [
            'comicIds' => [$comic->getId()],
            'maxUses' => 3,
            'senderResponsibilityAccepted' => true,
        ]);

        $this->createAndLoginUser(['email' => 'meddler@example.com']);
        // Reported as missing rather than forbidden, so an id cannot be probed
        // for whether it belongs to somebody's code.
        $this->deleteJson('/api/shares/claim-codes/' . $created['claimCode']['id']);
        self::assertResponseStatusCodeSame(404);

        $this->createAndLoginUser(['email' => 'still-welcome@example.com']);
        $this->postJson('/api/shares/claim-codes/redeem', ['code' => $created['code']]);
        self::assertResponseIsSuccessful();
    }

    public function testEveryIssuedCodeIsUniqueAcrossBothKinds(): void
    {
        $owner = $this->createAndLoginUser(['email' => 'many-codes@example.com']);
        $comic = ComicFactory::new()->ownedBy($owner)->create()->object();
        $seen = [$this->getJson('/api/shares/my-code')['sharingCode']];

        for ($i = 0; $i < 8; ++$i) {
            $seen[] = $this->postJson('/api/shares/claim-codes', [
                'comicIds' => [$comic->getId()],
                'maxUses' => 1,
                'senderResponsibilityAccepted' => true,
            ])['code'];
            self::assertResponseStatusCodeSame(201);
        }

        self::assertCount(count($seen), array_unique($seen));

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
        $this->postJson('/api/shares/claim-codes', [
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
        self::assertCount(1, $this->getJson('/api/shares/claim-codes')['codes']);

        // A month and a day later there is nothing left to look at.
        $expireAt('-31 days');
        $deletable = $repository->findDeletable(new \DateTimeImmutable(), 100);
        self::assertCount(1, $deletable);

        $entityManager->remove($deletable[0]);
        $entityManager->flush();

        // The comic is untouched by the sweep — a code is a way in, never the
        // access itself.
        self::assertSame([], $this->getJson('/api/shares/claim-codes')['codes']);
        self::assertNotNull($entityManager->getRepository(Comic::class)->find($comic->getId()));
    }

    public function testEveryCodeEndpointRequiresAuthentication(): void
    {
        $accept = ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json'];

        $this->browser()->request('GET', '/api/shares/my-code', [], [], $accept);
        self::assertResponseStatusCodeSame(401);

        $this->browser()->request('POST', '/api/shares/resolve-code', [], [], $accept, '{"sharingCode":"AAAA-AAAA-AAAA"}');
        self::assertResponseStatusCodeSame(401);

        $this->browser()->request('GET', '/api/shares/claim-codes', [], [], $accept);
        self::assertResponseStatusCodeSame(401);

        $this->browser()->request('POST', '/api/shares/claim-codes/redeem', [], [], $accept, '{"code":"AAAA-AAAA-AAAA"}');
        self::assertResponseStatusCodeSame(401);
    }
}
