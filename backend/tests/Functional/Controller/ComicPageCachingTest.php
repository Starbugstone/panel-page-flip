<?php

namespace App\Tests\Functional\Controller;

use App\Entity\User;
use App\Service\ComicPageDelivery;
use App\Tests\Factory\ComicFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Functional\AbstractApiTestCase;

/**
 * Reading a comic is the hot path: every page turn fetches a full-size image.
 * Those responses have to be cacheable, or turning back a page costs another
 * download of something the browser already had.
 */
final class ComicPageCachingTest extends AbstractApiTestCase
{
    /** @var list<string> */
    private array $temporaryFiles = [];

    /** @var list<string> */
    private array $temporaryDirectories = [];

    public function testPageResponseIsCacheableAndValidated(): void
    {
        [$owner, $comic] = $this->createComicWithArchive();
        $this->loginAs($owner);

        $this->browser()->request('GET', sprintf('/api/comics/%d/pages/1', $comic->getId()));

        self::assertResponseIsSuccessful();
        $headers = $this->browser()->getResponse()->headers;
        // Pages leave as WebP whatever the comic was stored as — the CBZ behind
        // this test holds JPEGs. A server whose GD cannot write WebP serves the
        // source bytes instead, which is a fallback rather than a failure.
        self::assertSame(
            self::getContainer()->get(ComicPageDelivery::class)->deliveryFormat() === ComicPageDelivery::FORMAT_WEBP
                ? 'image/webp'
                : 'image/jpeg',
            $headers->get('content-type')
        );
        $cacheControl = (string) $headers->get('cache-control');
        self::assertStringContainsString('private', $cacheControl);
        self::assertStringContainsString('max-age=86400', $cacheControl);
        self::assertStringNotContainsString('no-cache', $cacheControl);
        self::assertNotNull($headers->get('etag'));
        self::assertNotNull($headers->get('last-modified'));
    }

    public function testMatchingEtagReturnsNotModified(): void
    {
        [$owner, $comic] = $this->createComicWithArchive();
        $this->loginAs($owner);
        $url = sprintf('/api/comics/%d/pages/1', $comic->getId());

        $this->browser()->request('GET', $url);
        $etag = $this->browser()->getResponse()->headers->get('etag');
        self::assertNotNull($etag);

        $this->browser()->request('GET', $url, [], [], ['HTTP_IF_NONE_MATCH' => $etag]);

        self::assertResponseStatusCodeSame(304);
    }

    /**
     * Two pages of the same comic must not share a validator, or the browser
     * would answer page 2 with the bytes it cached for page 1.
     */
    public function testEachPageHasItsOwnValidator(): void
    {
        [$owner, $comic] = $this->createComicWithArchive();
        $this->loginAs($owner);

        $this->browser()->request('GET', sprintf('/api/comics/%d/pages/1', $comic->getId()));
        $firstEtag = $this->browser()->getResponse()->headers->get('etag');

        $this->browser()->request('GET', sprintf('/api/comics/%d/pages/2', $comic->getId()));
        $secondEtag = $this->browser()->getResponse()->headers->get('etag');

        self::assertNotSame($firstEtag, $secondEtag);

        // The first page's validator must not satisfy a request for the second.
        $this->browser()->request(
            'GET',
            sprintf('/api/comics/%d/pages/2', $comic->getId()),
            [],
            [],
            ['HTTP_IF_NONE_MATCH' => $firstEtag]
        );

        self::assertResponseIsSuccessful();
    }

    /**
     * The delivery contract: one format out, whatever went in, and the second
     * request for a page is answered from the cache rather than by decoding the
     * source again.
     */
    public function testPagesAreDeliveredAsCachedWebp(): void
    {
        $delivery = self::getContainer()->get(ComicPageDelivery::class);
        if ($delivery->deliveryFormat() !== ComicPageDelivery::FORMAT_WEBP) {
            self::markTestSkipped('This build of GD cannot write WebP.');
        }

        [$owner, $comic] = $this->createComicWithArchive();
        $this->loginAs($owner);
        $url = sprintf('/api/comics/%d/pages/1', $comic->getId());

        $this->browser()->request('GET', $url);
        self::assertResponseIsSuccessful();
        $first = (string) $this->browser()->getResponse()->getContent();
        self::assertSame('image/webp', $this->browser()->getResponse()->headers->get('content-type'));
        self::assertSame('image/webp', (string) (getimagesizefromstring($first)['mime'] ?? ''));

        // A cache entry must now exist for this comic, and the bytes served on
        // a second request must be exactly the ones stored.
        $cacheDirectory = self::getContainer()->getParameter('page_cache_directory').'/'.$comic->getId();
        $this->temporaryDirectories[] = $cacheDirectory;
        self::assertNotSame([], glob($cacheDirectory.'/1-*.webp') ?: [], 'Page 1 should have been cached.');

        $this->browser()->request('GET', $url);
        self::assertResponseIsSuccessful();
        self::assertSame($first, (string) $this->browser()->getResponse()->getContent());
    }

    /**
     * Deleting a comic has to take its generated pages with it, or a later
     * comic issued the same identifier would inherit them.
     */
    public function testDeletingAComicDropsItsCachedPages(): void
    {
        $delivery = self::getContainer()->get(ComicPageDelivery::class);
        if ($delivery->deliveryFormat() !== ComicPageDelivery::FORMAT_WEBP) {
            self::markTestSkipped('This build of GD cannot write WebP.');
        }

        [$owner, $comic] = $this->createComicWithArchive();
        $this->loginAs($owner);
        $comicId = $comic->getId();

        $this->browser()->request('GET', sprintf('/api/comics/%d/pages/1', $comicId));
        self::assertResponseIsSuccessful();

        $cacheDirectory = self::getContainer()->getParameter('page_cache_directory').'/'.$comicId;
        $this->temporaryDirectories[] = $cacheDirectory;
        self::assertDirectoryExists($cacheDirectory);

        self::getContainer()->get(\App\Service\ComicService::class)->quarantineComicFiles($comic);

        self::assertDirectoryDoesNotExist($cacheDirectory, 'Cached pages should not outlive the comic.');
    }

    public function testAnotherUserCannotReadAPage(): void
    {
        [, $comic] = $this->createComicWithArchive();
        $this->loginAs(UserFactory::createOne()->object());

        $this->browser()->request('GET', sprintf('/api/comics/%d/pages/1', $comic->getId()));

        self::assertResponseStatusCodeSame(404);
    }

    /**
     * @return array{0: User, 1: \App\Entity\Comic}
     */
    private function createComicWithArchive(): array
    {
        $owner = UserFactory::createOne()->object();
        $filename = 'pages-' . uniqid('', false) . '.cbz';
        $comic = ComicFactory::createOne([
            'owner' => $owner,
            'filePath' => $filename,
            'pageCount' => 2,
        ])->object();

        $directory = self::getContainer()->getParameter('comics_directory') . '/' . $owner->getId();
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            self::fail('Could not create the test comic directory.');
        }
        // Track the archive, never the directory: cleanup must not be able to
        // reach anything this test did not put there.
        $this->temporaryFiles[] = $directory . '/' . $filename;

        // Two distinguishable one-pixel JPEGs, so the per-page validators differ
        // for a real reason rather than by accident.
        $zip = new \ZipArchive();
        if ($zip->open($directory . '/' . $filename, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            self::fail('Could not create the test CBZ.');
        }
        $zip->addFromString('page-01.jpg', $this->onePixelJpeg());
        $zip->addFromString('page-02.jpg', $this->onePixelJpeg() . "\x00");
        $zip->close();

        return [$owner, $comic];
    }

    private function onePixelJpeg(): string
    {
        return base64_decode(
            '/9j/4AAQSkZJRgABAQEAYABgAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8UHRofHh0a'
            . 'HBwgJC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPC4zNDL/wAALCAABAAEBAREA/8QAHwAAAQUBAQEB'
            . 'AQEAAAAAAAAAAAECAwQFBgcICQoL/8QAtRAAAgEDAwIEAwUFBAQAAAF9AQIDAAQRBRIhMUEGE1Fh'
            . 'ByJxFDKBkaEII0KxwRVS0fAkM2JyggkKFhcYGRolJicoKSo0NTY3ODk6Q0RFRkdISUpTVFVWV1hZ'
            . 'WmNkZWZnaGlqc3R1dnd4eXqDhIWGh4iJipKTlJWWl5iZmqKjpKWmp6ipqrKztLW2t7i5usLDxMXG'
            . 'x8jJytLT1NXW19jZ2uHi4+Tl5ufo6erx8vP09fb3+Pn6/9oACAEBAAA/AP/Z'
        );
    }

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        $this->temporaryFiles = [];

        // Only directories this test asked for, and only their own entries.
        foreach ($this->temporaryDirectories as $directory) {
            if (!is_dir($directory)) continue;
            foreach (glob($directory.'/*') ?: [] as $file) {
                if (is_file($file)) unlink($file);
            }
            rmdir($directory);
        }
        $this->temporaryDirectories = [];

        parent::tearDown();
    }
}
