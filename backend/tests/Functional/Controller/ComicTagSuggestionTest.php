<?php

namespace App\Tests\Functional\Controller;

use App\Metadata\Classification;
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
        $owner = UserFactory::createOne();
        TagFactory::createOne(['name' => 'marvel', 'creator' => $owner]);
        $comic = ComicFactory::createOne([
            'owner' => $owner,
            'title' => 'Amazing Spider-Man',
            'publisher' => 'Marvel Comics',
            'tags' => [],
        ]);

        $this->loginAs($owner);
        $tags = $this->getJson(sprintf('/api/comics/%d/metadata-suggestions', $comic->getId()))['tags'];

        self::assertResponseIsSuccessful();
        self::assertSame(['marvel'], array_column($tags, 'name'));
        self::assertSame('publisher', $tags[0]['matchedField']);
        self::assertSame('Marvel Comics', $tags[0]['matchedValue']);
    }

    public function testProposesAGlobalTagAsWellAsTheUsersOwn(): void
    {
        $owner = UserFactory::createOne();
        TagFactory::createOne(['name' => 'marvel', 'isGlobal' => true]);
        $comic = ComicFactory::createOne(['owner' => $owner, 'publisher' => 'Marvel', 'tags' => []]);

        $this->loginAs($owner);
        $tags = $this->getJson(sprintf('/api/comics/%d/metadata-suggestions', $comic->getId()))['tags'];

        self::assertSame(['marvel'], array_column($tags, 'name'));
        self::assertTrue($tags[0]['isGlobal']);
    }

    /** Somebody else's private tag is not in this library and is not proposed. */
    public function testNeverProposesAnotherUsersTag(): void
    {
        $owner = UserFactory::createOne();
        TagFactory::createOne(['name' => 'marvel', 'creator' => UserFactory::createOne()]);
        $comic = ComicFactory::createOne(['owner' => $owner, 'publisher' => 'Marvel', 'tags' => []]);

        $this->loginAs($owner);

        self::assertSame([], $this->getJson(sprintf('/api/comics/%d/metadata-suggestions', $comic->getId()))['tags']);
    }

    /**
     * The reason matching is on whole words: a two-letter publisher tag would
     * otherwise land on every comic with those letters anywhere in its title.
     */
    public function testDoesNotMatchOnPartOfAWord(): void
    {
        $owner = UserFactory::createOne();
        TagFactory::createOne(['name' => 'dc', 'creator' => $owner]);
        $comic = ComicFactory::createOne([
            'owner' => $owner,
            'title' => 'Abduction',
            'publisher' => 'Image',
            'tags' => [],
        ]);

        $this->loginAs($owner);

        self::assertSame([], $this->getJson(sprintf('/api/comics/%d/metadata-suggestions', $comic->getId()))['tags']);
    }

    /** A multi-word tag matches across punctuation, which titles are full of. */
    public function testMatchesAMultiWordTagAcrossPunctuation(): void
    {
        $owner = UserFactory::createOne();
        TagFactory::createOne(['name' => 'spider man', 'creator' => $owner]);
        $comic = ComicFactory::createOne([
            'owner' => $owner,
            'title' => 'The Amazing Spider-Man',
            'publisher' => null,
            'tags' => [],
        ]);

        $this->loginAs($owner);
        $tags = $this->getJson(sprintf('/api/comics/%d/metadata-suggestions', $comic->getId()))['tags'];

        self::assertSame(['spider man'], array_column($tags, 'name'));
        self::assertSame('title', $tags[0]['matchedField']);
    }

    public function testDoesNotProposeATagTheComicAlreadyHas(): void
    {
        $owner = UserFactory::createOne();
        $tag = TagFactory::createOne(['name' => 'marvel', 'creator' => $owner]);
        $comic = ComicFactory::createOne([
            'owner' => $owner,
            'publisher' => 'Marvel',
            'tags' => [$tag],
        ]);

        $this->loginAs($owner);

        self::assertSame([], $this->getJson(sprintf('/api/comics/%d/metadata-suggestions', $comic->getId()))['tags']);
    }

    public function testSaysNothingWhenNoTagLooksRelevant(): void
    {
        $owner = UserFactory::createOne();
        TagFactory::createOne(['name' => 'manga', 'creator' => $owner]);
        $comic = ComicFactory::createOne([
            'owner' => $owner,
            'title' => 'Saga',
            'publisher' => 'Image',
            'tags' => [],
        ]);

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
        $owner = UserFactory::createOne();
        $administrator = UserFactory::new()->admin()->create();
        // Both would match the comic, so ownership is the only thing that can
        // separate them.
        TagFactory::createOne(['name' => 'marvel', 'creator' => $owner]);
        TagFactory::createOne(['name' => 'detective', 'creator' => $administrator]);

        $comic = ComicFactory::createOne([
            'owner' => $owner,
            'publisher' => 'Marvel',
            'title' => 'Detective Comics',
            'tags' => [],
        ]);

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
        $owner = UserFactory::createOne();
        $administrator = UserFactory::new()->admin()->create();
        $comic = ComicFactory::createOne(['owner' => $owner, 'tags' => []]);

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
        $owner = UserFactory::createOne();
        TagFactory::createOne(['name' => 'marvel', 'creator' => $owner]);
        $comic = ComicFactory::createOne(['owner' => $owner, 'publisher' => 'Marvel', 'tags' => []]);

        $this->loginAs($owner);
        $this->getJson(sprintf('/api/comics/%d/metadata-suggestions', $comic->getId()));

        $stored = $this->getJson(sprintf('/api/comics/%d', $comic->getId()))['comic'];
        self::assertSame([], $stored['tags']);
    }

    /**
     * ComicInfo's own genres are still a third party's opinion about how a
     * library should be organised, and the archive that carried them was often
     * packaged by somebody other than the reader. They are offered, not applied.
     */
    public function testProposesGenresFromTheFileWithoutCreatingTags(): void
    {
        $owner = UserFactory::createOne();
        $comic = ComicFactory::createOne([
            'owner' => $owner,
            'title' => 'The Boys',
            'tags' => [],
            'classification' => new Classification(genres: ['Superhero', 'Crime']),
        ]);

        $this->loginAs($owner);
        $tags = $this->getJson(sprintf('/api/comics/%d/metadata-suggestions', $comic->getId()))['tags'];

        self::assertSame(['Superhero', 'Crime'], array_column($tags, 'name'));
        foreach ($tags as $tag) {
            self::assertSame('genre', $tag['kind']);
            self::assertSame('comicinfo', $tag['source']);
            // Proposed, and not yet a tag anywhere.
            self::assertFalse($tag['exists']);
        }

        self::assertSame([], TagFactory::repository()->findAll());
    }

    /**
     * Characters, teams, locations and story arcs are structured metadata, not
     * organisational tags. A crossover names dozens; a library enriched that way
     * would end up with thousands nobody chose.
     */
    public function testDoesNotProposeCharactersTeamsOrStoryArcsAsTags(): void
    {
        $owner = UserFactory::createOne();
        $comic = ComicFactory::createOne([
            'owner' => $owner,
            'tags' => [],
            'classification' => new Classification(
                characters: ['Billy Butcher'],
                teams: ['The Seven'],
                locations: ['New York'],
                storyArcs: ['Herogasm'],
            ),
        ]);

        $this->loginAs($owner);
        $response = $this->getJson(sprintf('/api/comics/%d/metadata-suggestions', $comic->getId()));

        self::assertSame([], $response['tags']);
        // Still visible as metadata — just never as a proposed tag.
        self::assertSame(['Billy Butcher'], $response['classification']['characters']);
        self::assertSame(['Herogasm'], $response['classification']['storyArcs']);
    }

    /** The user's own spelling wins, so accepting cannot make a near-duplicate. */
    public function testPrefersTheSpellingOfATagTheLibraryAlreadyHas(): void
    {
        $owner = UserFactory::createOne();
        TagFactory::createOne(['name' => 'Science Fiction', 'creator' => $owner]);
        $comic = ComicFactory::createOne([
            'owner' => $owner,
            'tags' => [],
            'classification' => new Classification(genres: ['science fiction']),
        ]);

        $this->loginAs($owner);
        $tags = $this->getJson(sprintf('/api/comics/%d/metadata-suggestions', $comic->getId()))['tags'];

        $genres = array_values(array_filter($tags, static fn (array $t): bool => $t['kind'] === 'genre'));
        self::assertSame('Science Fiction', $genres[0]['name']);
        self::assertTrue($genres[0]['exists']);
    }

    /** Once it is on the comic, proposing it again is noise. */
    public function testDoesNotProposeAGenreTheComicAlreadyCarries(): void
    {
        $owner = UserFactory::createOne();
        $tag = TagFactory::createOne(['name' => 'Superhero', 'creator' => $owner]);
        $comic = ComicFactory::createOne([
            'owner' => $owner,
            'tags' => [$tag],
            'classification' => new Classification(genres: ['Superhero', 'Crime']),
        ]);

        $this->loginAs($owner);
        $tags = $this->getJson(sprintf('/api/comics/%d/metadata-suggestions', $comic->getId()))['tags'];

        self::assertSame(['Crime'], array_column($tags, 'name'));
    }
}
