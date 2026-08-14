<?php

namespace App\Tests\Functional\Controller;

use App\Tests\Factory\ComicFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Functional\AbstractApiTestCase;

/**
 * Looking a comic up spends the installation's provider allowance, so it takes
 * the right to edit the comic rather than merely to read it.
 */
final class ComicMetadataCandidatesTest extends AbstractApiTestCase
{
    public function testOwnerGetsAnEmptyListWhileNoProviderIsConfigured(): void
    {
        $owner = UserFactory::createOne()->object();
        $comic = ComicFactory::createOne(['owner' => $owner, 'series' => 'Batman'])->object();

        $this->loginAs($owner);
        $response = $this->getJson(sprintf('/api/comics/%d/metadata-candidates', $comic->getId()));

        self::assertResponseIsSuccessful();
        self::assertSame([], $response['candidates']);
    }

    public function testAStrangerIsRefused(): void
    {
        $comic = ComicFactory::createOne(['owner' => UserFactory::createOne()->object()])->object();

        $this->loginAs(UserFactory::createOne()->object());
        $this->getJson(sprintf('/api/comics/%d/metadata-candidates', $comic->getId()));

        self::assertResponseStatusCodeSame(403);
    }

    public function testAnonymousIsRefused(): void
    {
        $comic = ComicFactory::createOne(['owner' => UserFactory::createOne()->object()])->object();

        $this->getJson(sprintf('/api/comics/%d/metadata-candidates', $comic->getId()));

        self::assertResponseStatusCodeSame(401);
    }

    public function testAMissingComicIsNotFound(): void
    {
        $this->loginAs(UserFactory::createOne()->object());
        $this->getJson('/api/comics/99999999/metadata-candidates');

        self::assertResponseStatusCodeSame(404);
    }

    public function testAnUnknownProviderIsRejected(): void
    {
        $owner = UserFactory::createOne()->object();
        $comic = ComicFactory::createOne(['owner' => $owner])->object();

        $this->loginAs($owner);
        $this->getJson(sprintf('/api/comics/%d/metadata-candidates?provider=nope', $comic->getId()));

        self::assertResponseStatusCodeSame(400);
    }

    public function testAKnownProviderIsAccepted(): void
    {
        $owner = UserFactory::createOne()->object();
        $comic = ComicFactory::createOne(['owner' => $owner, 'series' => 'Batman'])->object();

        $this->loginAs($owner);
        $this->getJson(sprintf('/api/comics/%d/metadata-candidates?provider=metron', $comic->getId()));

        self::assertResponseIsSuccessful();
    }
}
