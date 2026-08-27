<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Tests\Functional\AbstractApiTestCase;

/**
 * The bulk-upload session over HTTP.
 *
 * Every assertion here is really the same one: the session describes a batch and
 * never withholds a feature. With advertising off — the shipped default — the
 * gate is not even offered.
 */
final class BulkUploadSessionControllerTest extends AbstractApiTestCase
{
    public function testASessionBelongsToAnAccountAndIsRefusedWithoutOne(): void
    {
        $this->getJson('/api/upload/bulk/session');

        self::assertResponseStatusCodeSame(401);
    }

    public function testNoGateIsOfferedWhereAdvertisingIsOff(): void
    {
        $this->createAndLoginUser();

        $payload = $this->getJson('/api/upload/bulk/session');

        self::assertResponseIsSuccessful();
        self::assertFalse($payload['gateRequired']);
        self::assertFalse($payload['active']);
    }

    public function testOpeningABatchAndFinishingItAreBothRecordedServerSide(): void
    {
        $this->createAndLoginUser();

        $opened = $this->postJson('/api/upload/bulk/session', ['rewarded' => true]);
        self::assertResponseIsSuccessful();
        self::assertTrue($opened['active']);
        self::assertTrue($opened['rewarded']);
        self::assertNotNull($opened['expiresAt']);

        self::assertTrue($this->getJson('/api/upload/bulk/session')['active']);

        $closed = $this->deleteJson('/api/upload/bulk/session');
        self::assertResponseIsSuccessful();
        self::assertFalse($closed['active']);
        self::assertFalse($this->getJson('/api/upload/bulk/session')['active']);
    }

    /**
     * The reward flag is a note about what the browser reported, not a claim the
     * server can check — so a request that omits it opens a batch just the same,
     * recorded as unrewarded.
     */
    public function testABatchOpensWithoutAnyRewardClaim(): void
    {
        $this->createAndLoginUser();

        $opened = $this->postJson('/api/upload/bulk/session');

        self::assertTrue($opened['active']);
        self::assertFalse($opened['rewarded']);
    }

    public function testARewardClaimCannotBeSmuggledInAsSomethingOtherThanTrue(): void
    {
        $this->createAndLoginUser();

        self::assertFalse($this->postJson('/api/upload/bulk/session', ['rewarded' => 'yes'])['rewarded']);
        self::assertFalse($this->postJson('/api/upload/bulk/session', ['rewarded' => 1])['rewarded']);
    }

    public function testOneAccountCannotSeeAnothersBatch(): void
    {
        $this->createAndLoginUser();
        $this->postJson('/api/upload/bulk/session', ['rewarded' => true]);

        $this->createAndLoginUser();

        self::assertFalse($this->getJson('/api/upload/bulk/session')['active']);
    }
}
