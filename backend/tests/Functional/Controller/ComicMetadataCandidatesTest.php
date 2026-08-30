<?php

namespace App\Tests\Functional\Controller;

use App\Tests\Factory\ComicFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Functional\AbstractApiTestCase;

/**
 * Looking a comic up spends provider allowance, so it takes the right to edit
 * the comic rather than merely to read it.
 *
 * No provider is configured in the test environment and the shared switches
 * default to off, so nothing here reaches the network: every lookup is refused
 * before a request would be built. That is the point of most of these — a
 * refusal has to be legible, not silently empty.
 */
final class ComicMetadataCandidatesTest extends AbstractApiTestCase
{
    public function testOwnerGetsNoCandidatesWhileNoProviderIsConfigured(): void
    {
        $owner = UserFactory::createOne();
        $comic = ComicFactory::createOne(['owner' => $owner, 'series' => 'Batman']);

        $this->loginAs($owner);
        $response = $this->postJson(sprintf('/api/comics/%d/metadata-candidates', $comic->getId()));

        self::assertResponseIsSuccessful();
        self::assertSame([], $response['candidates']);
    }

    /**
     * A user is told which providers will answer them, and given a reason only
     * where the reason is theirs. Why the *server's* credential was refused is
     * operator configuration — see ProviderSecrecyTest.
     */
    public function testSaysWhichProvidersWouldAnswer(): void
    {
        $owner = UserFactory::createOne();
        $comic = ComicFactory::createOne(['owner' => $owner, 'series' => 'Batman']);

        $this->loginAs($owner);
        $response = $this->postJson(sprintf('/api/comics/%d/metadata-candidates', $comic->getId()));

        $byKey = array_column($response['providers'], null, 'key');
        self::assertFalse($byKey['metron']['available']);
        self::assertNotSame('', $byKey['metron']['reason']);
        self::assertFalse($byKey['comicvine']['available']);
    }

    /**
     * The search runs off what is in the edit form, so accepting a filename
     * suggestion no longer means saving and reopening before it can be used.
     */
    public function testSearchesTheStagedValuesRatherThanOnlyTheSavedOnes(): void
    {
        $owner = UserFactory::createOne();
        $comic = ComicFactory::createOne(['owner' => $owner, 'series' => null, 'title' => 'untitled']);

        $this->loginAs($owner);
        $response = $this->postJson(sprintf('/api/comics/%d/metadata-candidates', $comic->getId()), [
            'query' => ['series' => 'The Boys', 'issueNumber' => '7', 'year' => 2006],
        ]);

        self::assertResponseIsSuccessful();
        self::assertSame('The Boys', $response['query']['series']);
        self::assertSame('7', $response['query']['issueNumber']);
        self::assertSame(2006, $response['query']['year']);
    }

    /** A staged value is a search hint, never authority over another comic. */
    public function testStagedValuesDoNotWidenWhatMayBeEdited(): void
    {
        $comic = ComicFactory::createOne(['owner' => UserFactory::createOne()]);

        $this->loginAs(UserFactory::createOne());
        $this->postJson(sprintf('/api/comics/%d/metadata-candidates', $comic->getId()), [
            'query' => ['series' => 'Batman'],
        ]);

        // Answered as a missing comic, not a refused one: a stranger who can
        // tell the two apart has been handed a way to count somebody's library.
        self::assertResponseStatusCodeSame(404);
    }

    public function testAComicWithNothingToSearchOnIsRejected(): void
    {
        $owner = UserFactory::createOne();
        $comic = ComicFactory::createOne(['owner' => $owner, 'series' => null, 'title' => '']);

        $this->loginAs($owner);
        $this->postJson(sprintf('/api/comics/%d/metadata-candidates', $comic->getId()));

        self::assertResponseStatusCodeSame(422);
    }

    public function testAStrangerIsRefused(): void
    {
        $comic = ComicFactory::createOne(['owner' => UserFactory::createOne()]);

        $this->loginAs(UserFactory::createOne());
        $this->postJson(sprintf('/api/comics/%d/metadata-candidates', $comic->getId()));

        self::assertResponseStatusCodeSame(404);
    }

    public function testAnonymousIsRefused(): void
    {
        $comic = ComicFactory::createOne(['owner' => UserFactory::createOne()]);

        $this->postJson(sprintf('/api/comics/%d/metadata-candidates', $comic->getId()));

        self::assertResponseStatusCodeSame(401);
    }

    public function testAMissingComicIsNotFound(): void
    {
        $this->loginAs(UserFactory::createOne());
        $this->postJson('/api/comics/99999999/metadata-candidates');

        self::assertResponseStatusCodeSame(404);
    }

    public function testAnUnknownProviderIsRejected(): void
    {
        $owner = UserFactory::createOne();
        $comic = ComicFactory::createOne(['owner' => $owner, 'series' => 'Batman']);

        $this->loginAs($owner);
        $this->postJson(sprintf('/api/comics/%d/metadata-candidates', $comic->getId()), ['provider' => 'nope']);

        self::assertResponseStatusCodeSame(400);
    }

    public function testAKnownProviderIsAccepted(): void
    {
        $owner = UserFactory::createOne();
        $comic = ComicFactory::createOne(['owner' => $owner, 'series' => 'Batman']);

        $this->loginAs($owner);
        $this->postJson(sprintf('/api/comics/%d/metadata-candidates', $comic->getId()), ['provider' => 'metron']);

        self::assertResponseIsSuccessful();
    }

    /**
     * An administrator can withdraw external lookups per account. Local
     * sources are unaffected, which the suggestions endpoint covers.
     */
    public function testAUserWithoutMetadataApiAccessIsToldSo(): void
    {
        $owner = UserFactory::createOne(['metadataApiEnabled' => false]);
        $comic = ComicFactory::createOne(['owner' => $owner, 'series' => 'Batman']);

        $this->loginAs($owner);
        $response = $this->postJson(sprintf('/api/comics/%d/metadata-candidates', $comic->getId()));

        self::assertResponseIsSuccessful();
        $byKey = array_column($response['providers'], null, 'key');
        self::assertFalse($byKey['metron']['available']);
        // The reason is about them, which is the one thing they are told.
        self::assertStringContainsString('your account', $byKey['metron']['reason']);
    }

    /** Refreshing before anything was ever matched is a state, not an error. */
    public function testRefreshingAComicThatWasNeverMatchedSaysSo(): void
    {
        $owner = UserFactory::createOne();
        $comic = ComicFactory::createOne(['owner' => $owner, 'series' => 'Batman']);

        $this->loginAs($owner);
        $this->postJson(sprintf('/api/comics/%d/metadata-refresh', $comic->getId()));

        self::assertResponseStatusCodeSame(409);
    }

    public function testFetchingARecordNeedsAProviderAndAnId(): void
    {
        $owner = UserFactory::createOne();
        $comic = ComicFactory::createOne(['owner' => $owner]);

        $this->loginAs($owner);
        $this->postJson(sprintf('/api/comics/%d/metadata-record', $comic->getId()), ['provider' => 'metron']);

        self::assertResponseStatusCodeSame(400);
    }

    public function testFetchingARecordIsRefusedToAStranger(): void
    {
        $comic = ComicFactory::createOne(['owner' => UserFactory::createOne()]);

        $this->loginAs(UserFactory::createOne());
        $this->postJson(sprintf('/api/comics/%d/metadata-record', $comic->getId()), [
            'provider' => 'metron',
            'externalId' => '1',
        ]);

        self::assertResponseStatusCodeSame(404);
    }
}
