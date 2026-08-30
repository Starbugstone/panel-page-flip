<?php

namespace App\Tests\Functional\Controller;

use App\Tests\Factory\ComicFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Functional\AbstractApiTestCase;

final class ComicCoverSecurityTest extends AbstractApiTestCase
{
    /** @var list<string> */
    private array $temporaryCoverFiles = [];

    public function testOwnerReceivesPlaceholderWhenStoredCoverIsMissing(): void
    {
        $owner = UserFactory::createOne();
        $comic = ComicFactory::createOne([
            'owner' => $owner,
            'coverImagePath' => 'covers/missing/cover.png',
        ]);
        $this->loginAs($owner);

        $this->browser()->request('GET', sprintf('/api/comics/cover/%d/%d/cover.png', $owner->getId(), $comic->getId()));

        self::assertResponseIsSuccessful();
        self::assertSame('image/png', $this->browser()->getResponse()->headers->get('content-type'));
    }

    public function testAnotherUserCannotReadAnOwnersCover(): void
    {
        $owner = UserFactory::createOne();
        $comic = ComicFactory::createOne([
            'owner' => $owner,
            'coverImagePath' => 'covers/missing/cover.png',
        ]);
        $this->loginAs(UserFactory::createOne());

        $this->browser()->request('GET', sprintf('/api/comics/cover/%d/%d/cover.png', $owner->getId(), $comic->getId()));

        // Missing, as far as a stranger is concerned. A 403 here would have
        // confirmed the comic exists to somebody with no business knowing, and
        // covers are addressed by owner and comic id — the two numbers an
        // enumerator would most like to have checked for them.
        self::assertResponseStatusCodeSame(404);
    }

    public function testAdminCanReadAnotherUsersCover(): void
    {
        $owner = UserFactory::createOne();
        $comic = ComicFactory::createOne([
            'owner' => $owner,
            'coverImagePath' => 'covers/missing/cover.png',
        ]);
        $this->loginAs(UserFactory::new()->admin()->create());

        $this->browser()->request('GET', sprintf('/api/comics/cover/%d/%d/cover.png', $owner->getId(), $comic->getId()));

        self::assertResponseIsSuccessful();
    }

    public function testStoredCoverIsCachedImmutablyByTheOwnersBrowser(): void
    {
        [$owner, $url] = $this->createComicWithCoverFile();
        $this->loginAs($owner);

        $this->browser()->request('GET', $url);

        self::assertResponseIsSuccessful();
        $headers = $this->browser()->getResponse()->headers;
        $cacheControl = (string) $headers->get('cache-control');
        // Private, because the cover is only reachable through an authenticated
        // endpoint and must never be held by a shared cache.
        self::assertStringContainsString('private', $cacheControl);
        self::assertStringContainsString('max-age=31536000', $cacheControl);
        self::assertStringContainsString('immutable', $cacheControl);
        self::assertStringNotContainsString('no-cache', $cacheControl);
        self::assertNotNull($headers->get('etag'));
        self::assertNotNull($headers->get('last-modified'));
    }

    public function testCachedCoversAreKeyedByTheSessionCookie(): void
    {
        [$owner, $url] = $this->createComicWithCoverFile();
        $this->loginAs($owner);

        $this->browser()->request('GET', $url);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Cookie', (string) $this->browser()->getResponse()->headers->get('vary'));

        // The same URL under a different account. An admin is the only other
        // account allowed to read it, and a cache entry that ignored the cookie
        // would be the one holding the previous session's copy.
        $this->loginAs(UserFactory::new()->admin()->create());
        $this->browser()->request('GET', $url);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Cookie', (string) $this->browser()->getResponse()->headers->get('vary'));
    }

    public function testMatchingEtagReturnsNotModified(): void
    {
        [$owner, $url] = $this->createComicWithCoverFile();
        $this->loginAs($owner);

        $this->browser()->request('GET', $url);
        $etag = $this->browser()->getResponse()->headers->get('etag');
        self::assertNotNull($etag);

        $this->browser()->request('GET', $url, [], [], ['HTTP_IF_NONE_MATCH' => $etag]);

        self::assertResponseStatusCodeSame(304);
        // A 304 must carry no body: the point is that the cover is not resent.
        self::assertFalse($this->browser()->getResponse()->headers->has('content-length'));
    }

    public function testUnmodifiedSinceReturnsNotModified(): void
    {
        [$owner, $url] = $this->createComicWithCoverFile();
        $this->loginAs($owner);

        $this->browser()->request('GET', $url);
        $lastModified = $this->browser()->getResponse()->headers->get('last-modified');
        self::assertNotNull($lastModified);

        $this->browser()->request('GET', $url, [], [], ['HTTP_IF_MODIFIED_SINCE' => $lastModified]);

        self::assertResponseStatusCodeSame(304);
    }

    public function testPlaceholderIsCacheableButStaysRevalidatable(): void
    {
        $owner = UserFactory::createOne();
        $comic = ComicFactory::createOne([
            'owner' => $owner,
            'coverImagePath' => 'covers/missing/cover.png',
        ]);
        $this->loginAs($owner);
        $url = sprintf('/api/comics/cover/%d/%d/cover.png', $owner->getId(), $comic->getId());

        $this->browser()->request('GET', $url);

        self::assertResponseIsSuccessful();
        $headers = $this->browser()->getResponse()->headers;
        $cacheControl = (string) $headers->get('cache-control');
        self::assertStringContainsString('private', $cacheControl);
        self::assertStringContainsString('max-age=300', $cacheControl);
        // The placeholder answers an un-versioned URL: a comic that regains its
        // cover file must not be stuck behind a year-long immutable entry.
        self::assertStringNotContainsString('immutable', $cacheControl);
        self::assertNotNull($headers->get('etag'));

        $etag = $headers->get('etag');
        $this->browser()->request('GET', $url, [], [], ['HTTP_IF_NONE_MATCH' => $etag]);

        self::assertResponseStatusCodeSame(304);
    }

    public function testCoverFilenameMustMatchTheStoredCover(): void
    {
        $owner = UserFactory::createOne();
        $comic = ComicFactory::createOne([
            'owner' => $owner,
            'coverImagePath' => 'covers/missing/cover.png',
        ]);
        $this->loginAs($owner);

        $this->browser()->request('GET', sprintf('/api/comics/cover/%d/%d/other.png', $owner->getId(), $comic->getId()));

        self::assertResponseStatusCodeSame(404);
    }

    /**
     * Write a real cover file where the controller expects to find it, so the
     * conditional-request assertions run against genuine file metadata.
     *
     * @return array{0: \App\Entity\User, 1: string}
     */
    private function createComicWithCoverFile(): array
    {
        $owner = UserFactory::createOne();
        $filename = 'cover-' . uniqid('', false) . '.png';
        $comic = ComicFactory::createOne([
            'owner' => $owner,
            'coverImagePath' => 'covers/stored/' . $filename,
        ]);

        $directory = self::getContainer()->getParameter('comics_directory')
            . '/' . $owner->getId() . '/covers/stored';
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            self::fail('Could not create the test cover directory.');
        }
        // A one-pixel PNG: the bytes only need to be a real, stable file.
        file_put_contents($directory . '/' . $filename, base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='
        ));
        // Track the file, never its directory: cleanup must not be able to reach
        // anything this test did not put there.
        $this->temporaryCoverFiles[] = $directory . '/' . $filename;

        return [$owner, sprintf('/api/comics/cover/%d/%d/%s', $owner->getId(), $comic->getId(), $filename)];
    }

    protected function tearDown(): void
    {
        foreach ($this->temporaryCoverFiles as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        $this->temporaryCoverFiles = [];

        parent::tearDown();
    }
}
