<?php

namespace App\Tests\Functional\Controller;

use App\Tests\Factory\ComicFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Functional\AbstractApiTestCase;

/**
 * Accepting a suggestion is an ordinary edit, so it goes through the ordinary
 * update route and is authorised the same way.
 */
final class ComicStructuredMetadataUpdateTest extends AbstractApiTestCase
{
    public function testAcceptsTheStructuredFields(): void
    {
        $owner = UserFactory::createOne()->object();
        $comic = ComicFactory::createOne(['owner' => $owner])->object();
        $this->loginAs($owner);

        $this->putJson(sprintf('/api/comics/%d', $comic->getId()), [
            'title' => $comic->getTitle(),
            'series' => 'Batman',
            'issueNumber' => '7',
            'issueCount' => 13,
            'volume' => 1996,
            'publishedAt' => '1997-04-09',
            'languageCode' => 'EN',
            'ageRating' => 'Teen',
        ]);

        self::assertResponseIsSuccessful();
        $stored = $this->getJson(sprintf('/api/comics/%d', $comic->getId()))['comic'];

        self::assertSame('Batman', $stored['series']);
        self::assertSame('7', $stored['issueNumber']);
        self::assertSame(13, $stored['issueCount']);
        self::assertSame(1996, $stored['volume']);
        self::assertSame('1997-04-09', $stored['publishedAt']);
        self::assertSame('en', $stored['languageCode']);
        self::assertSame('Teen', $stored['ageRating']);
    }

    /**
     * Accepting one suggestion must not blank the fields the user left alone,
     * which is what a payload carrying only the accepted field has to mean.
     */
    public function testLeavesFieldsThePayloadDoesNotMentionAlone(): void
    {
        $owner = UserFactory::createOne()->object();
        $comic = ComicFactory::createOne(['owner' => $owner, 'series' => 'Batman', 'issueNumber' => '7'])->object();
        $this->loginAs($owner);

        $this->putJson(sprintf('/api/comics/%d', $comic->getId()), ['volume' => 1996]);

        self::assertResponseIsSuccessful();
        $stored = $this->getJson(sprintf('/api/comics/%d', $comic->getId()))['comic'];

        self::assertSame('Batman', $stored['series']);
        self::assertSame('7', $stored['issueNumber']);
        self::assertSame(1996, $stored['volume']);
    }

    public function testClearsAFieldSentAsNull(): void
    {
        $owner = UserFactory::createOne()->object();
        $comic = ComicFactory::createOne(['owner' => $owner, 'series' => 'Batman'])->object();
        $this->loginAs($owner);

        $this->putJson(sprintf('/api/comics/%d', $comic->getId()), ['series' => null]);

        self::assertResponseIsSuccessful();
        self::assertNull($this->getJson(sprintf('/api/comics/%d', $comic->getId()))['comic']['series']);
    }

    /**
     * @dataProvider rejectedPayloads
     * @param array<string, mixed> $payload
     */
    public function testRejectsWhatItCannotStore(array $payload): void
    {
        $owner = UserFactory::createOne()->object();
        $comic = ComicFactory::createOne(['owner' => $owner])->object();
        $this->loginAs($owner);

        $this->putJson(sprintf('/api/comics/%d', $comic->getId()), $payload);

        self::assertResponseStatusCodeSame(422);
    }

    public function rejectedPayloads(): iterable
    {
        yield 'series is not a string' => [['series' => ['Batman']]];
        yield 'issue number too long' => [['issueNumber' => str_repeat('9', 51)]];
        yield 'volume is zero' => [['volume' => 0]];
        yield 'volume is not a number' => [['volume' => 'nineteen']];
        yield 'language is free text' => [['languageCode' => 'definitely not a code']];
        yield 'date is not a date' => [['publishedAt' => 'last tuesday']];
        yield 'date is not real' => [['publishedAt' => '2011-02-30']];
    }

    /** A rejected payload must leave the comic exactly as it was. */
    public function testChangesNothingWhenThePayloadIsRejected(): void
    {
        $owner = UserFactory::createOne()->object();
        $comic = ComicFactory::createOne(['owner' => $owner, 'series' => 'Batman'])->object();
        $this->loginAs($owner);

        $this->putJson(sprintf('/api/comics/%d', $comic->getId()), ['series' => 'Detective Comics', 'volume' => 0]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('Batman', $this->getJson(sprintf('/api/comics/%d', $comic->getId()))['comic']['series']);
    }

    public function testAStrangerMayNotApplyAnything(): void
    {
        $comic = ComicFactory::createOne(['owner' => UserFactory::createOne()->object()])->object();
        $this->loginAs(UserFactory::createOne()->object());

        $this->putJson(sprintf('/api/comics/%d', $comic->getId()), ['series' => 'Batman']);

        // Not "you may not edit that", which would confirm there is a that.
        self::assertResponseStatusCodeSame(404);
    }
}
