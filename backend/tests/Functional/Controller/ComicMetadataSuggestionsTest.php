<?php

namespace App\Tests\Functional\Controller;

use App\Tests\Factory\ComicFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Functional\AbstractApiTestCase;

/**
 * Suggestions describe a comic, so reading them needs the same right as reading
 * the comic. Nothing here may write.
 */
final class ComicMetadataSuggestionsTest extends AbstractApiTestCase
{
    public function testOwnerSeesWhatTheFilenameImplies(): void
    {
        $owner = UserFactory::createOne()->object();
        $comic = ComicFactory::createOne([
            'owner' => $owner,
            'originalFilename' => 'Batman - 007 (2011) (Digital).cbz',
            'series' => null,
            'issueNumber' => null,
        ])->object();

        $this->loginAs($owner);
        $this->browser()->request('GET', sprintf('/api/comics/%d/metadata-suggestions', $comic->getId()));

        self::assertResponseIsSuccessful();
        $suggestions = json_decode((string) $this->browser()->getResponse()->getContent(), true)['suggestions'];

        $byField = array_column($suggestions, null, 'field');
        self::assertSame('Batman', $byField['series']['suggested']);
        self::assertSame('7', $byField['issueNumber']['suggested']);
        self::assertSame('filename', $byField['series']['source']);
        self::assertTrue($byField['series']['fillsGap']);
    }

    public function testReturnsNothingWhenTheFilenameSaysNothingUseful(): void
    {
        $owner = UserFactory::createOne()->object();
        $comic = ComicFactory::createOne(['owner' => $owner, 'originalFilename' => null])->object();

        $this->loginAs($owner);
        $this->browser()->request('GET', sprintf('/api/comics/%d/metadata-suggestions', $comic->getId()));

        self::assertResponseIsSuccessful();
        self::assertSame([], json_decode((string) $this->browser()->getResponse()->getContent(), true)['suggestions']);
    }

    public function testAStrangerIsRefused(): void
    {
        $comic = ComicFactory::createOne([
            'owner' => UserFactory::createOne()->object(),
            'originalFilename' => 'Batman - 007 (2011).cbz',
        ])->object();

        $this->loginAs(UserFactory::createOne()->object());
        $this->browser()->request('GET', sprintf('/api/comics/%d/metadata-suggestions', $comic->getId()));

        self::assertResponseStatusCodeSame(403);
    }

    public function testAnonymousIsRefused(): void
    {
        $comic = ComicFactory::createOne([
            'owner' => UserFactory::createOne()->object(),
            'originalFilename' => 'Batman - 007 (2011).cbz',
        ])->object();

        $this->browser()->request('GET', sprintf('/api/comics/%d/metadata-suggestions', $comic->getId()));

        self::assertResponseStatusCodeSame(401);
    }

    public function testAMissingComicIsNotFound(): void
    {
        $this->loginAs(UserFactory::createOne()->object());
        $this->browser()->request('GET', '/api/comics/99999999/metadata-suggestions');

        self::assertResponseStatusCodeSame(404);
    }

    /** Reading suggestions must never change the comic they describe. */
    public function testReadingSuggestionsChangesNothing(): void
    {
        $owner = UserFactory::createOne()->object();
        $comic = ComicFactory::createOne([
            'owner' => $owner,
            'originalFilename' => 'Batman - 007 (2011).cbz',
            'series' => null,
        ])->object();

        $this->loginAs($owner);
        $this->browser()->request('GET', sprintf('/api/comics/%d/metadata-suggestions', $comic->getId()));
        self::assertResponseIsSuccessful();

        $this->browser()->request('GET', sprintf('/api/comics/%d', $comic->getId()));
        $payload = json_decode((string) $this->browser()->getResponse()->getContent(), true);

        self::assertNull($payload['comic']['series']);
        self::assertNull($payload['comic']['issueNumber']);
    }
}
