<?php

namespace App\Tests\Functional\Controller;

use App\Tests\Factory\ComicFactory;
use App\Tests\Factory\TagFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Functional\AbstractApiTestCase;

/**
 * Tags a library already has that look like they belong to a comic.
 *
 * The rule the whole feature turns on: nothing here creates a tag. A provider
 * saying a comic is published by Marvel is not grounds for inventing a "marvel"
 * tag in somebody's library.
 */
final class ComicTagSuggestionTest extends AbstractApiTestCase
{
    public function testProposesAnExistingTagThatMatchesThePublisher(): void
    {
        $owner = UserFactory::createOne()->object();
        TagFactory::createOne(['name' => 'marvel', 'creator' => $owner]);
        $comic = ComicFactory::createOne([
            'owner' => $owner,
            'title' => 'Amazing Spider-Man',
            'publisher' => 'Marvel Comics',
            'tags' => [],
        ])->object();

        $this->loginAs($owner);
        $tags = $this->getJson(sprintf('/api/comics/%d/metadata-suggestions', $comic->getId()))['tags'];

        self::assertResponseIsSuccessful();
        self::assertSame(['marvel'], array_column($tags, 'name'));
        self::assertSame('publisher', $tags[0]['matchedField']);
        self::assertSame('Marvel Comics', $tags[0]['matchedValue']);
    }

    public function testProposesAGlobalTagAsWellAsTheUsersOwn(): void
    {
        $owner = UserFactory::createOne()->object();
        TagFactory::createOne(['name' => 'marvel', 'isGlobal' => true]);
        $comic = ComicFactory::createOne(['owner' => $owner, 'publisher' => 'Marvel', 'tags' => []])->object();

        $this->loginAs($owner);
        $tags = $this->getJson(sprintf('/api/comics/%d/metadata-suggestions', $comic->getId()))['tags'];

        self::assertSame(['marvel'], array_column($tags, 'name'));
        self::assertTrue($tags[0]['isGlobal']);
    }

    /** Somebody else's private tag is not in this library and is not proposed. */
    public function testNeverProposesAnotherUsersTag(): void
    {
        $owner = UserFactory::createOne()->object();
        TagFactory::createOne(['name' => 'marvel', 'creator' => UserFactory::createOne()->object()]);
        $comic = ComicFactory::createOne(['owner' => $owner, 'publisher' => 'Marvel', 'tags' => []])->object();

        $this->loginAs($owner);

        self::assertSame([], $this->getJson(sprintf('/api/comics/%d/metadata-suggestions', $comic->getId()))['tags']);
    }

    /**
     * The reason matching is on whole words: a two-letter publisher tag would
     * otherwise land on every comic with those letters anywhere in its title.
     */
    public function testDoesNotMatchOnPartOfAWord(): void
    {
        $owner = UserFactory::createOne()->object();
        TagFactory::createOne(['name' => 'dc', 'creator' => $owner]);
        $comic = ComicFactory::createOne([
            'owner' => $owner,
            'title' => 'Abduction',
            'publisher' => 'Image',
            'tags' => [],
        ])->object();

        $this->loginAs($owner);

        self::assertSame([], $this->getJson(sprintf('/api/comics/%d/metadata-suggestions', $comic->getId()))['tags']);
    }

    /** A multi-word tag matches across punctuation, which titles are full of. */
    public function testMatchesAMultiWordTagAcrossPunctuation(): void
    {
        $owner = UserFactory::createOne()->object();
        TagFactory::createOne(['name' => 'spider man', 'creator' => $owner]);
        $comic = ComicFactory::createOne([
            'owner' => $owner,
            'title' => 'The Amazing Spider-Man',
            'publisher' => null,
            'tags' => [],
        ])->object();

        $this->loginAs($owner);
        $tags = $this->getJson(sprintf('/api/comics/%d/metadata-suggestions', $comic->getId()))['tags'];

        self::assertSame(['spider man'], array_column($tags, 'name'));
        self::assertSame('title', $tags[0]['matchedField']);
    }

    public function testDoesNotProposeATagTheComicAlreadyHas(): void
    {
        $owner = UserFactory::createOne()->object();
        $tag = TagFactory::createOne(['name' => 'marvel', 'creator' => $owner])->object();
        $comic = ComicFactory::createOne([
            'owner' => $owner,
            'publisher' => 'Marvel',
            'tags' => [$tag],
        ])->object();

        $this->loginAs($owner);

        self::assertSame([], $this->getJson(sprintf('/api/comics/%d/metadata-suggestions', $comic->getId()))['tags']);
    }

    public function testSaysNothingWhenNoTagLooksRelevant(): void
    {
        $owner = UserFactory::createOne()->object();
        TagFactory::createOne(['name' => 'manga', 'creator' => $owner]);
        $comic = ComicFactory::createOne([
            'owner' => $owner,
            'title' => 'Saga',
            'publisher' => 'Image',
            'tags' => [],
        ])->object();

        $this->loginAs($owner);

        self::assertSame([], $this->getJson(sprintf('/api/comics/%d/metadata-suggestions', $comic->getId()))['tags']);
    }

    /**
     * An administrator editing somebody else's comic is offered that library's
     * tags, not their own. Anything else offers a choice the write path cannot
     * honour: a save resolves tag names against the owner, so accepting an
     * administrator's private tag would silently create a new one in the
     * owner's library under the same name.
     */
    public function testAnAdministratorSeesTheOwnersTagsNotTheirOwn(): void
    {
        $owner = UserFactory::createOne()->object();
        $administrator = UserFactory::new()->admin()->create()->object();
        // Both would match the comic, so ownership is the only thing that can
        // separate them.
        TagFactory::createOne(['name' => 'marvel', 'creator' => $owner]);
        TagFactory::createOne(['name' => 'detective', 'creator' => $administrator]);

        $comic = ComicFactory::createOne([
            'owner' => $owner,
            'publisher' => 'Marvel',
            'title' => 'Detective Comics',
            'tags' => [],
        ])->object();

        $this->loginAs($administrator);
        $names = array_column(
            $this->getJson(sprintf('/api/comics/%d/metadata-suggestions', $comic->getId()))['tags'],
            'name'
        );

        self::assertContains('marvel', $names);
        self::assertNotContains('detective', $names);
    }

    /**
     * The save side of the same rule, on the route that actually permits it —
     * the batch route only ever loads the caller's own comics, so an
     * administrator reaches somebody else's through the single-comic update.
     */
    public function testATagSavedOnAnothersComicBelongsToTheOwner(): void
    {
        $owner = UserFactory::createOne()->object();
        $administrator = UserFactory::new()->admin()->create()->object();
        $comic = ComicFactory::createOne(['owner' => $owner, 'tags' => []])->object();

        $this->loginAs($administrator);
        $this->putJson(sprintf('/api/comics/%d', $comic->getId()), [
            'title' => $comic->getTitle(),
            'tags' => ['brand-new-tag'],
        ]);
        self::assertResponseIsSuccessful();

        $tag = TagFactory::repository()->findOneBy(['name' => 'brand-new-tag']);
        self::assertNotNull($tag);
        self::assertSame($owner->getId(), $tag->getCreator()?->getId());
    }

    /** Reading suggestions must not attach anything. */
    public function testProposingATagDoesNotApplyIt(): void
    {
        $owner = UserFactory::createOne()->object();
        TagFactory::createOne(['name' => 'marvel', 'creator' => $owner]);
        $comic = ComicFactory::createOne(['owner' => $owner, 'publisher' => 'Marvel', 'tags' => []])->object();

        $this->loginAs($owner);
        $this->getJson(sprintf('/api/comics/%d/metadata-suggestions', $comic->getId()));

        $stored = $this->getJson(sprintf('/api/comics/%d', $comic->getId()))['comic'];
        self::assertSame([], $stored['tags']);
    }
}
