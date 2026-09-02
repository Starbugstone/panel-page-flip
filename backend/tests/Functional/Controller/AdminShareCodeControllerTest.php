<?php

namespace App\Tests\Functional\Controller;

use App\Entity\ComicShare;
use App\Entity\ShareClaimCode;
use App\Service\ExpiredShareCleanupService;
use App\Service\SecurityAuditLogger;
use App\Tests\Factory\ComicFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Functional\AbstractApiTestCase;
use App\Tests\Functional\SecurityLogAssertions;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The operational surface for issued claim codes.
 *
 * Claim codes are capabilities that leave the building, so somebody has to be
 * able to see what is outstanding and stop one. Most of what is asserted here
 * is what this surface must *not* do: expose a code reserved for its owner,
 * take back comics that were already claimed, or delete anything the nightly
 * sweep would have left alone.
 */
final class AdminShareCodeControllerTest extends AbstractApiTestCase
{
    use SecurityLogAssertions;

    public function testOrdinaryUsersCannotReachAnyOfIt(): void
    {
        $owner = $this->createAndLoginUser(['email' => 'ordinary@example.com']);
        $comic = ComicFactory::new()->ownedBy($owner)->create();
        $created = $this->postJson('/api/shares/comic-codes', [
            'comicIds' => [$comic->getId()],
            'maxUses' => 1,
            'senderResponsibilityAccepted' => true,
        ]);

        $this->getJson('/api/admin/sharing-codes');
        self::assertResponseStatusCodeSame(403);

        $this->postJson(sprintf('/api/admin/sharing-codes/%d/revoke', $created['contentCode']['id']), []);
        self::assertResponseStatusCodeSame(403);

        $this->postJson('/api/admin/sharing-codes/cleanup', []);
        self::assertResponseStatusCodeSame(403);

        // And the code they could not revoke is still live.
        self::assertTrue($this->getJson('/api/shares/content-codes')['codes'][0]['isRedeemable']);
    }

    public function testAnAdministratorSeesIssuedCodesWithoutEverSeeingACode(): void
    {
        $owner = UserFactory::createOne(['email' => 'issuer@example.com', 'name' => 'Issuer']);
        $this->loginAs($owner);
        $comic = ComicFactory::new()->ownedBy($owner)->create(['title' => 'Handed Out']);
        $plaintext = $this->postJson('/api/shares/comic-codes', [
            'comicIds' => [$comic->getId()],
            'maxUses' => 4,
            'senderResponsibilityAccepted' => true,
        ])['code'];

        $this->createAndLoginAdmin(['email' => 'ops@example.com']);
        $payload = $this->getJson('/api/admin/sharing-codes');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $payload['items']);

        $entry = $payload['items'][0];
        self::assertSame((int) $owner->getId(), $entry['ownerId']);
        self::assertSame('issuer@example.com', $entry['ownerEmail']);
        self::assertSame(4, $entry['maxUses']);
        self::assertSame(0, $entry['timesUsed']);
        self::assertSame('Handed Out', $entry['comics'][0]['title']);
        self::assertNotNull($entry['deletableAfter']);
        self::assertSame(1, $payload['pagination']['totalItems']);

        // The sweep dialog describes two retention windows on two clocks, so
        // the list hands it both rather than letting it assume they agree.
        self::assertSame(
            ltrim(ShareClaimCode::RETENTION_AFTER_EXPIRY, '+'),
            $payload['retentionAfterExpiry']
        );
        self::assertSame(
            ltrim(ComicShare::RETENTION_AFTER_REVOCATION, '+'),
            $payload['retentionAfterRevocation']
        );

        // An encrypted copy exists for the owner, but the administrator's list
        // deliberately exposes neither it nor the redemption hash.
        $body = (string) $this->browser()->getResponse()->getContent();
        self::assertStringNotContainsString(str_replace('-', '', $plaintext), str_replace('-', '', $body));
        self::assertArrayNotHasKey('code', $entry);
        self::assertArrayNotHasKey('codeHash', $entry);
    }

    public function testTheListPagesAndFiltersByStatus(): void
    {
        $owner = UserFactory::createOne(['email' => 'prolific@example.com']);
        $other = UserFactory::createOne(['email' => 'somebody-else@example.com']);
        $this->loginAs($owner);

        $ids = [];
        for ($i = 0; $i < 3; ++$i) {
            $comic = ComicFactory::new()->ownedBy($owner)->create();
            $ids[] = $this->postJson('/api/shares/comic-codes', [
                'comicIds' => [$comic->getId()],
                'maxUses' => 1,
                'senderResponsibilityAccepted' => true,
            ])['contentCode']['id'];
        }

        $this->loginAs($other);
        $othersComic = ComicFactory::new()->ownedBy($other)->create();
        $this->postJson('/api/shares/comic-codes', [
            'comicIds' => [$othersComic->getId()],
            'maxUses' => 1,
            'senderResponsibilityAccepted' => true,
        ]);

        $admin = $this->createAndLoginAdmin(['email' => 'pager@example.com']);

        // Paged, because this table grows continuously between sweeps.
        $firstPage = $this->getJson('/api/admin/sharing-codes?limit=2');
        self::assertCount(2, $firstPage['items']);
        self::assertSame(4, $firstPage['pagination']['totalItems']);
        self::assertSame(2, $firstPage['pagination']['totalPages']);
        self::assertCount(2, $this->getJson('/api/admin/sharing-codes?limit=2&page=2')['items']);

        // Narrowed to one owner, which is how an abuse report arrives.
        $mine = $this->getJson(sprintf('/api/admin/sharing-codes?ownerId=%d', $owner->getId()));
        self::assertSame(3, $mine['pagination']['totalItems']);

        $searched = $this->getJson('/api/admin/sharing-codes?search=somebody-else');
        self::assertSame(1, $searched['pagination']['totalItems']);

        $columnFiltered = $this->getJson('/api/admin/sharing-codes?' . http_build_query([
            'filterId' => $ids[1],
            'filterOwner' => (string) $owner->getEmail(),
            'filterComics' => '1',
            'filterUses' => '0 / 1',
            'filterStatus' => 'active',
            'sort' => 'status',
            'direction' => 'ASC',
        ]));
        self::assertResponseIsSuccessful();
        self::assertSame([$ids[1]], array_column($columnFiltered['items'], 'id'));
        self::assertSame(0, $columnFiltered['usesMax']);

        $usesRange = $this->getJson('/api/admin/sharing-codes?filterUses=' . urlencode('0..1'));
        self::assertResponseIsSuccessful();
        self::assertCount(4, $usesRange['items']);

        $byOwner = $this->getJson('/api/admin/sharing-codes?sort=owner&direction=ASC');
        self::assertResponseIsSuccessful();
        self::assertCount(4, $byOwner['items']);

        $unmatched = $this->getJson('/api/admin/sharing-codes?filterStatus=nonsense');
        self::assertResponseIsSuccessful();
        self::assertSame([], $unmatched['items'], 'a status nobody shows excludes every code');

        // Column dates use the same strict YYYY-MM-DD contract as the other
        // admin tables. DateTime's relative words must not turn into a hidden
        // filter of their own.
        $invalidDate = $this->getJson('/api/admin/sharing-codes?filterCreatedAt=tomorrow');
        self::assertResponseIsSuccessful();
        self::assertSame(4, $invalidDate['pagination']['totalItems']);

        // Withdraw one and the status filters separate the two halves.
        $this->postJson(sprintf('/api/admin/sharing-codes/%d/revoke', $ids[0]), []);
        self::assertResponseIsSuccessful();

        self::assertSame(3, $this->getJson('/api/admin/sharing-codes?status=active')['pagination']['totalItems']);
        $withdrawn = $this->getJson('/api/admin/sharing-codes?status=withdrawn');
        self::assertSame(1, $withdrawn['pagination']['totalItems']);
        self::assertSame('withdrawn', $withdrawn['items'][0]['deadReason']);
        self::assertNotNull($withdrawn['items'][0]['revokedAt']);

        // The chip and the per-column filter accumulate. Contradictory filters
        // cannot silently fall back to the chip alone.
        $contradictory = $this->getJson('/api/admin/sharing-codes?status=active&filterStatus=withdrawn');
        self::assertSame([], $contradictory['items']);

        // Active and Withdrawn both contain \"i\", so both kinds survive a
        // substring filter (as do any other matching labels).
        $partial = $this->getJson('/api/admin/sharing-codes?filterStatus=i');
        self::assertSame(4, $partial['pagination']['totalItems']);
        self::assertNotNull($admin->getId());
    }

    public function testAnAdministratorCanStopACodeWithoutTakingBackWhatItAlreadyGaveOut(): void
    {
        $owner = UserFactory::createOne(['email' => 'reported@example.com']);
        $this->loginAs($owner);
        $comic = ComicFactory::new()->ownedBy($owner)->create();
        $created = $this->postJson('/api/shares/comic-codes', [
            'comicIds' => [$comic->getId()],
            'maxUses' => 5,
            'senderResponsibilityAccepted' => true,
        ]);

        $claimer = $this->createAndLoginUser(['email' => 'got-there-first@example.com']);
        $this->postJson('/api/shares/content-codes/redeem', ['code' => $created['code']]);
        self::assertResponseIsSuccessful();

        $this->createAndLoginAdmin(['email' => 'responder@example.com']);
        $payload = $this->postJson(sprintf('/api/admin/sharing-codes/%d/revoke', $created['contentCode']['id']), []);

        self::assertResponseIsSuccessful();
        self::assertTrue($payload['contentCode']['isRevoked']);
        self::assertFalse($payload['contentCode']['isRedeemable']);

        // Nobody else gets in.
        $this->createAndLoginUser(['email' => 'too-slow@example.com']);
        $this->postJson('/api/shares/content-codes/redeem', ['code' => $created['code']]);
        self::assertResponseStatusCodeSame(404);

        // But withdrawing a code stops the way in, never the access already
        // granted. Taking a comic back is moderation, not code management.
        $this->loginAs($claimer);
        $received = $this->getJson('/api/shares/shared-with-me')['sharedWithMe'];
        self::assertCount(1, $received);
        self::assertSame(ComicShare::STATUS_ACCEPTED, $received[0]['status']);
        self::assertTrue($received[0]['canRead']);
    }

    public function testAdministrativeRevocationIsAudited(): void
    {
        $owner = UserFactory::createOne(['email' => 'audited-owner@example.com']);
        $this->loginAs($owner);
        $comic = ComicFactory::new()->ownedBy($owner)->create();
        $created = $this->postJson('/api/shares/comic-codes', [
            'comicIds' => [$comic->getId()],
            'maxUses' => 2,
            'senderResponsibilityAccepted' => true,
        ]);

        $admin = $this->createAndLoginAdmin(['email' => 'accountable@example.com']);
        $this->postJson(sprintf('/api/admin/sharing-codes/%d/revoke', $created['contentCode']['id']), []);
        self::assertResponseIsSuccessful();

        $record = $this->assertLoggedAuditEvent(SecurityAuditLogger::SHARE_CLAIM_CODE_REVOKED);
        self::assertSame($admin->getId(), $record->context['actor_user_id']);
        self::assertSame((int) $owner->getId(), $record->context['owner_user_id']);
        self::assertSame($created['contentCode']['id'], $record->context['target_id']);
        self::assertTrue($record->context['by_admin']);

        // The code itself is never recoverable and never written down.
        self::assertStringNotContainsString(
            str_replace('-', '', $created['code']),
            str_replace('-', '', json_encode($record->context, JSON_THROW_ON_ERROR))
        );
    }

    public function testManualCleanupRemovesOnlyWhatTheScheduledSweepWould(): void
    {
        $owner = UserFactory::createOne(['email' => 'housekeeper@example.com']);
        $this->loginAs($owner);

        $codeIds = [];
        for ($i = 0; $i < 3; ++$i) {
            $comic = ComicFactory::new()->ownedBy($owner)->create();
            $codeIds[] = $this->postJson('/api/shares/comic-codes', [
                'comicIds' => [$comic->getId()],
                'maxUses' => 1,
                'senderResponsibilityAccepted' => true,
            ])['contentCode']['id'];
        }

        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $expire = static function (int $id, string $modifier) use ($entityManager): void {
            $entityManager->getConnection()->executeStatement(
                'UPDATE share_claim_code SET expires_at = :when WHERE id = :id',
                ['when' => (new \DateTimeImmutable($modifier))->format('Y-m-d H:i:s'), 'id' => $id]
            );
        };

        // One live, one dead but inside the retention window, one past it.
        $expire($codeIds[1], '-2 days');
        $expire($codeIds[2], '-40 days');
        $entityManager->clear();

        $this->createAndLoginAdmin(['email' => 'sweeper@example.com']);
        $payload = $this->postJson('/api/admin/sharing-codes/cleanup', []);

        self::assertResponseIsSuccessful();
        self::assertSame(1, $payload['contentCodesRemoved']);

        $remaining = array_column($this->getJson('/api/admin/sharing-codes')['items'], 'id');
        // The live one and the recently expired one both survive: pressing the
        // button early must not delete what the nightly job would have kept.
        self::assertEqualsCanonicalizing([$codeIds[0], $codeIds[1]], $remaining);
    }

    public function testManualCleanupNeverRemovesTheSharesACodeProduced(): void
    {
        $owner = UserFactory::createOne(['email' => 'gave-away@example.com']);
        $this->loginAs($owner);
        $comic = ComicFactory::new()->ownedBy($owner)->create(['title' => 'Claimed Long Ago']);
        $created = $this->postJson('/api/shares/comic-codes', [
            'comicIds' => [$comic->getId()],
            'maxUses' => 1,
            'senderResponsibilityAccepted' => true,
        ]);

        $claimer = $this->createAndLoginUser(['email' => 'keeps-it@example.com']);
        $this->postJson('/api/shares/content-codes/redeem', ['code' => $created['code']]);
        self::assertResponseIsSuccessful();

        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $entityManager->getConnection()->executeStatement(
            'UPDATE share_claim_code SET expires_at = :when WHERE id = :id',
            [
                'when' => (new \DateTimeImmutable('-40 days'))->format('Y-m-d H:i:s'),
                'id' => $created['contentCode']['id'],
            ]
        );
        $entityManager->clear();

        $this->createAndLoginAdmin(['email' => 'tidy@example.com']);
        $this->postJson('/api/admin/sharing-codes/cleanup', []);
        self::assertResponseIsSuccessful();
        self::assertSame([], $this->getJson('/api/admin/sharing-codes')['items']);

        // A code is a way in, never the access itself. Sweeping the code away
        // leaves the relationship it produced entirely alone.
        $this->loginAs($claimer);
        $received = $this->getJson('/api/shares/shared-with-me')['sharedWithMe'];
        self::assertCount(1, $received);
        self::assertSame('Claimed Long Ago', $received[0]['comicTitle']);
        self::assertTrue($received[0]['canRead']);
    }

    public function testManualCleanupIsAuditedWithWhoRanItAndWhatWentAway(): void
    {
        $owner = UserFactory::createOne(['email' => 'old-codes@example.com']);
        $this->loginAs($owner);
        $comic = ComicFactory::new()->ownedBy($owner)->create();
        $codeId = $this->postJson('/api/shares/comic-codes', [
            'comicIds' => [$comic->getId()],
            'maxUses' => 1,
            'senderResponsibilityAccepted' => true,
        ])['contentCode']['id'];

        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $entityManager->getConnection()->executeStatement(
            'UPDATE share_claim_code SET expires_at = :when WHERE id = :id',
            ['when' => (new \DateTimeImmutable('-40 days'))->format('Y-m-d H:i:s'), 'id' => $codeId]
        );
        $entityManager->clear();

        $admin = $this->createAndLoginAdmin(['email' => 'answerable@example.com']);
        $this->postJson('/api/admin/sharing-codes/cleanup', []);

        // The scheduled command is deliberately quiet; a person deleting
        // records from other people's accounts is not.
        $record = $this->assertLoggedAuditEvent(SecurityAuditLogger::RETENTION_CLEANUP);
        self::assertSame($admin->getId(), $record->context['actor_user_id']);
        self::assertSame('manual_admin_sweep', $record->context['scope']);
        self::assertSame(1, $record->context['claim_codes_removed']);
        self::assertSame(0, $record->context['revoked_shares_removed']);
    }

    /**
     * The button and the cron job must never disagree about what is rubbish,
     * which is why they are the same code and not two implementations.
     */
    public function testTheAdminSweepAndTheScheduledOneAreTheSameSweep(): void
    {
        $owner = UserFactory::createOne(['email' => 'both-ways@example.com']);
        $this->loginAs($owner);

        $ids = [];
        for ($i = 0; $i < 2; ++$i) {
            $comic = ComicFactory::new()->ownedBy($owner)->create();
            $ids[] = $this->postJson('/api/shares/comic-codes', [
                'comicIds' => [$comic->getId()],
                'maxUses' => 1,
                'senderResponsibilityAccepted' => true,
            ])['contentCode']['id'];
        }

        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $entityManager->getConnection()->executeStatement(
            'UPDATE share_claim_code SET expires_at = :when WHERE id = :id',
            ['when' => (new \DateTimeImmutable('-40 days'))->format('Y-m-d H:i:s'), 'id' => $ids[0]]
        );
        $entityManager->clear();

        // The service the command runs, invoked directly: it finds the one row
        // the admin endpoint would have removed and leaves the other.
        $cleanup = static::getContainer()->get(ExpiredShareCleanupService::class);
        self::assertSame(1, $cleanup->cleanupExpiredClaimCodes());
        self::assertSame(0, $cleanup->cleanupExpiredClaimCodes());

        $this->createAndLoginAdmin(['email' => 'nothing-left@example.com']);
        // Nothing for the button to do, because the cron job already did it.
        self::assertSame(0, $this->postJson('/api/admin/sharing-codes/cleanup', [])['contentCodesRemoved']);
        self::assertSame([$ids[1]], array_column($this->getJson('/api/admin/sharing-codes')['items'], 'id'));

        self::assertNotNull(ShareClaimCode::RETENTION_AFTER_EXPIRY);
    }
    /**
     * A group whose package has lost a comic is not active.
     *
     * It is live in every other respect — unrevoked, unexpired, uses left — and
     * it cannot be redeemed, because a group is handed over whole or not at
     * all. Listing it as active tells an operator it works, and there was no
     * filter that would find it so they could withdraw it and ask the owner to
     * reissue.
     */
    public function testACodeWithAMissingComicIsNotListedAsActive(): void
    {
        $owner = $this->createAndLoginUser(['email' => 'broken-package@example.com']);
        $first = ComicFactory::new()->ownedBy($owner)->create();
        $second = ComicFactory::new()->ownedBy($owner)->create();

        $this->postJson('/api/shares/group-codes', [
            'comicIds' => [$first->getId(), $second->getId()],
            'maxUses' => 3,
            'senderResponsibilityAccepted' => true,
        ]);
        self::assertResponseIsSuccessful();

        $this->createAndLoginAdmin(['email' => 'package-watcher@example.com']);
        self::assertSame(1, $this->getJson('/api/admin/sharing-codes?status=active')['pagination']['totalItems']);
        self::assertSame(0, $this->getJson('/api/admin/sharing-codes?status=comics_removed')['pagination']['totalItems']);

        // One issue of the arc goes away, so the arc can no longer be handed
        // over as the arc it was advertised as.
        $this->loginAs($owner);
        $this->browser()->request('DELETE', '/api/comics/' . $second->getId(), [], [], $this->csrfHeader());
        self::assertResponseIsSuccessful();

        $this->createAndLoginAdmin(['email' => 'package-watcher-2@example.com']);
        self::assertSame(0, $this->getJson('/api/admin/sharing-codes?status=active')['pagination']['totalItems']);

        $broken = $this->getJson('/api/admin/sharing-codes?status=comics_removed');
        self::assertSame(1, $broken['pagination']['totalItems']);
        self::assertSame('comics_removed', $broken['items'][0]['deadReason']);
    }
}
