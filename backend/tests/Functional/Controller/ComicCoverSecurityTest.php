<?php

namespace App\Tests\Functional\Controller;

use App\Tests\Factory\ComicFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Functional\AbstractApiTestCase;

final class ComicCoverSecurityTest extends AbstractApiTestCase
{
    public function testOwnerReceivesPlaceholderWhenStoredCoverIsMissing(): void
    {
        $owner = UserFactory::createOne()->object();
        $comic = ComicFactory::createOne([
            'owner' => $owner,
            'coverImagePath' => 'covers/missing/cover.png',
        ])->object();
        $this->loginAs($owner);

        $this->browser()->request('GET', sprintf('/api/comics/cover/%d/%d/cover.png', $owner->getId(), $comic->getId()));

        self::assertResponseIsSuccessful();
        self::assertSame('image/png', $this->browser()->getResponse()->headers->get('content-type'));
    }

    public function testAnotherUserCannotReadAnOwnersCover(): void
    {
        $owner = UserFactory::createOne()->object();
        $comic = ComicFactory::createOne([
            'owner' => $owner,
            'coverImagePath' => 'covers/missing/cover.png',
        ])->object();
        $this->loginAs(UserFactory::createOne()->object());

        $this->browser()->request('GET', sprintf('/api/comics/cover/%d/%d/cover.png', $owner->getId(), $comic->getId()));

        self::assertResponseStatusCodeSame(403);
    }

    public function testAdminCanReadAnotherUsersCover(): void
    {
        $owner = UserFactory::createOne()->object();
        $comic = ComicFactory::createOne([
            'owner' => $owner,
            'coverImagePath' => 'covers/missing/cover.png',
        ])->object();
        $this->loginAs(UserFactory::new()->admin()->create()->object());

        $this->browser()->request('GET', sprintf('/api/comics/cover/%d/%d/cover.png', $owner->getId(), $comic->getId()));

        self::assertResponseIsSuccessful();
    }
}
