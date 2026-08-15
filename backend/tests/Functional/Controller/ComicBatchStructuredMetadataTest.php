<?php

namespace App\Tests\Functional\Controller;

use App\Tests\Factory\ComicFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Functional\AbstractApiTestCase;

/**
 * The edit dialog saves through the batch route, not the single-comic one.
 *
 * That is the route an accepted suggestion actually travels, and it used to
 * drop the structured fields on the floor: the value was staged, the save
 * reported success, and nothing changed.
 */
final class ComicBatchStructuredMetadataTest extends AbstractApiTestCase
{
    public function testAppliesStructuredFieldsSentByTheEditDialog(): void
    {
        $owner = UserFactory::createOne()->object();
        $comic = ComicFactory::createOne(['owner' => $owner, 'series' => null])->object();
        $this->loginAs($owner);

        $this->patchJson('/api/comics', ['updates' => [[
            'id' => $comic->getId(),
            'changes' => [
                'title' => $comic->getTitle(),
                'series' => 'Batman',
                'issueNumber' => '7',
                'volume' => 1996,
                'publishedAt' => '1997-04-09',
            ],
        ]]]);

        self::assertResponseIsSuccessful();
        $stored = $this->getJson(sprintf('/api/comics/%d', $comic->getId()))['comic'];

        self::assertSame('Batman', $stored['series']);
        self::assertSame('7', $stored['issueNumber']);
        self::assertSame(1996, $stored['volume']);
        self::assertSame('1997-04-09', $stored['publishedAt']);
    }

    public function testClearsAStructuredFieldSentAsNull(): void
    {
        $owner = UserFactory::createOne()->object();
        $comic = ComicFactory::createOne(['owner' => $owner, 'series' => 'Batman'])->object();
        $this->loginAs($owner);

        $this->patchJson('/api/comics', ['updates' => [[
            'id' => $comic->getId(),
            'changes' => ['series' => null],
        ]]]);

        self::assertResponseIsSuccessful();
        self::assertNull($this->getJson(sprintf('/api/comics/%d', $comic->getId()))['comic']['series']);
    }

    public function testRejectsAStructuredValueItCannotStore(): void
    {
        $owner = UserFactory::createOne()->object();
        $comic = ComicFactory::createOne(['owner' => $owner, 'series' => 'Batman'])->object();
        $this->loginAs($owner);

        $this->patchJson('/api/comics', ['updates' => [[
            'id' => $comic->getId(),
            'changes' => ['series' => 'Detective Comics', 'volume' => 0],
        ]]]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('Batman', $this->getJson(sprintf('/api/comics/%d', $comic->getId()))['comic']['series']);
    }

    public function testStillRefusesAFieldThatIsNotOnTheList(): void
    {
        $owner = UserFactory::createOne()->object();
        $comic = ComicFactory::createOne(['owner' => $owner])->object();
        $this->loginAs($owner);

        $this->patchJson('/api/comics', ['updates' => [[
            'id' => $comic->getId(),
            'changes' => ['filePath' => '/etc/passwd'],
        ]]]);

        self::assertResponseStatusCodeSame(400);
    }

    /**
     * Not found rather than forbidden, deliberately: this route takes a list of
     * ids, and answering "forbidden" for one of them would confirm that a comic
     * with that id exists and belongs to somebody.
     */
    public function testAStrangerMayNotApplyAnything(): void
    {
        $comic = ComicFactory::createOne(['owner' => UserFactory::createOne()->object()])->object();
        $this->loginAs(UserFactory::createOne()->object());

        $this->patchJson('/api/comics', ['updates' => [[
            'id' => $comic->getId(),
            'changes' => ['series' => 'Batman'],
        ]]]);

        self::assertResponseStatusCodeSame(404);
        self::assertNotSame('Batman', ComicFactory::find($comic->getId())->getSeries());
    }

    /**
     * The same regression, one slice later.
     *
     * The review flow gained reviewed credits and a record of which external
     * record was accepted, and the edit dialog started sending them. An unknown
     * key does not get dropped here — it makes normaliseComicUpdates() reject
     * the whole batch — so accepting a provider match and saving from the
     * dashboard failed outright.
     */
    public function testAppliesReviewedCreditsAndTheAcceptedProviderMatch(): void
    {
        $owner = UserFactory::createOne()->object();
        $comic = ComicFactory::createOne(['owner' => $owner, 'series' => null])->object();
        $this->loginAs($owner);

        $this->patchJson('/api/comics', ['updates' => [[
            'id' => $comic->getId(),
            'changes' => [
                'title' => $comic->getTitle(),
                'series' => 'The Boys',
                'creators' => ['writer' => ['Garth Ennis']],
                'metadataProvider' => 'metron',
                'metadataExternalId' => '123925',
            ],
        ]]]);

        self::assertResponseIsSuccessful();
        $stored = $this->getJson(sprintf('/api/comics/%d', $comic->getId()))['comic'];

        self::assertSame(['writer' => ['Garth Ennis']], $stored['creators']);
        self::assertSame('metron', $stored['metadataOrigin']['provider']);
        self::assertSame('123925', $stored['metadataOrigin']['externalId']);
    }

    /** A key the endpoint does not know is still refused outright. */
    public function testRejectsABatchCarryingAnUnknownField(): void
    {
        $owner = UserFactory::createOne()->object();
        $comic = ComicFactory::createOne(['owner' => $owner])->object();
        $this->loginAs($owner);

        $this->patchJson('/api/comics', ['updates' => [[
            'id' => $comic->getId(),
            'changes' => ['title' => $comic->getTitle(), 'somethingElse' => 'nope'],
        ]]]);

        self::assertResponseStatusCodeSame(400);
    }
}
