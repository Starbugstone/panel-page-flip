<?php

namespace App\Tests\Functional\Controller;

use App\Entity\Comic;
use App\Entity\User;
use App\Service\ComicPageDelivery;
use App\Tests\Factory\ComicFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Functional\AbstractApiTestCase;

/**
 * What the reader is allowed to ask for, and what it gets back.
 *
 * A phone displaying a page across 400 CSS pixels has no use for the 1988-pixel
 * scan the uploader exported, and on a metered connection it is the difference
 * between a comic that reads and one that stalls. These tests pin the two halves
 * of that: a bounded set of sizes, and a bounded set of names for them.
 */
final class ComicPageVariantTest extends AbstractApiTestCase
{
    private const SOURCE_WIDTH = 1200;
    private const SOURCE_HEIGHT = 1800;

    /** @var list<string> */
    private array $temporaryFiles = [];

    /** @var list<string> */
    private array $temporaryDirectories = [];

    public function testAVariantIsServedAtItsBoundedWidth(): void
    {
        $this->requireWebp();
        [$owner, $comic] = $this->createComicWithArchive();
        $this->loginAs($owner);

        $size = $this->requestPageSize($comic, 1, 'reader-small');

        self::assertSame(800, $size[0]);
        // The page keeps its proportions: a reader that has laid out a slot for
        // an aspect ratio must not be handed a differently shaped image.
        self::assertSame(1200, $size[1]);
    }

    public function testAThumbnailIsMuchSmallerThanAReaderPage(): void
    {
        $this->requireWebp();
        [$owner, $comic] = $this->createComicWithArchive();
        $this->loginAs($owner);

        $thumbnail = $this->requestPage($comic, 1, 'thumb');
        $reader = $this->requestPage($comic, 1, 'reader-medium');

        self::assertSame(280, (int) (getimagesizefromstring($thumbnail)[0] ?? 0));
        self::assertLessThan(strlen($reader), strlen($thumbnail));
    }

    /**
     * A variant is a ceiling, not a target. Stretching a small page to fill one
     * would cost bytes to deliver exactly the same detail.
     */
    public function testASmallPageIsNotUpscaledToFillALargerVariant(): void
    {
        $this->requireWebp();
        [$owner, $comic] = $this->createComicWithArchive();
        $this->loginAs($owner);

        $size = $this->requestPageSize($comic, 1, 'reader-large');

        self::assertSame([self::SOURCE_WIDTH, self::SOURCE_HEIGHT], [$size[0], $size[1]]);
    }

    public function testEveryFormatOfSourcePageIsResized(): void
    {
        $this->requireWebp();
        [$owner, $comic] = $this->createComicWithArchive();
        $this->loginAs($owner);

        // page 1 is JPEG, page 2 PNG, page 3 WebP. Whatever the uploader
        // exported, the reader's bandwidth should not depend on it.
        foreach ([1, 2, 3] as $page) {
            $size = $this->requestPageSize($comic, $page, 'reader-small');
            self::assertSame(800, $size[0], sprintf('Page %d should have been resized.', $page));
        }
    }

    public function testAnUnknownVariantIsRefused(): void
    {
        [$owner, $comic] = $this->createComicWithArchive();
        $this->loginAs($owner);

        $this->browser()->request('GET', sprintf('/api/comics/%d/pages/1?variant=reader-enormous', $comic->getId()));

        self::assertResponseStatusCodeSame(400);
        $payload = json_decode((string) $this->browser()->getResponse()->getContent(), true);
        self::assertContains('reader-medium', $payload['variants'] ?? []);
    }

    public function testNamingNoVariantStillServesTheWholePage(): void
    {
        $this->requireWebp();
        [$owner, $comic] = $this->createComicWithArchive();
        $this->loginAs($owner);

        $size = $this->requestPageSize($comic, 1, null);

        self::assertSame([self::SOURCE_WIDTH, self::SOURCE_HEIGHT], [$size[0], $size[1]]);
    }

    /**
     * Two sizes of the same page are two different images, and a browser holding
     * one must not be told the other is what it already has.
     */
    public function testVariantsAreCachedAndValidatedSeparately(): void
    {
        $this->requireWebp();
        [$owner, $comic] = $this->createComicWithArchive();
        $this->loginAs($owner);

        $this->browser()->request('GET', sprintf('/api/comics/%d/pages/1?variant=thumb', $comic->getId()));
        $thumbnailEtag = $this->browser()->getResponse()->headers->get('etag');

        $this->browser()->request('GET', sprintf('/api/comics/%d/pages/1?variant=reader-medium', $comic->getId()));
        $readerEtag = $this->browser()->getResponse()->headers->get('etag');

        self::assertNotSame($thumbnailEtag, $readerEtag);

        $this->browser()->request(
            'GET',
            sprintf('/api/comics/%d/pages/1?variant=reader-medium', $comic->getId()),
            [],
            [],
            ['HTTP_IF_NONE_MATCH' => $thumbnailEtag]
        );
        self::assertResponseIsSuccessful();

        $directory = $this->cacheDirectory($comic);
        self::assertNotSame([], glob($directory.'/1-thumb-*.webp') ?: []);
        self::assertNotSame([], glob($directory.'/1-reader-medium-*.webp') ?: []);
    }

    public function testASecondRequestIsAnsweredFromTheCache(): void
    {
        $this->requireWebp();
        [$owner, $comic] = $this->createComicWithArchive();
        $this->loginAs($owner);
        $url = sprintf('/api/comics/%d/pages/1?variant=reader-small', $comic->getId());

        $this->browser()->request('GET', $url);
        $first = (string) $this->browser()->getResponse()->getContent();

        $cached = glob($this->cacheDirectory($comic).'/1-reader-small-*.webp') ?: [];
        self::assertCount(1, $cached);
        self::assertSame($first, (string) file_get_contents($cached[0]));

        $this->browser()->request('GET', $url);
        self::assertSame($first, (string) $this->browser()->getResponse()->getContent());
    }

    /**
     * Replacing the comic's file has to invalidate everything generated from the
     * previous one, or the reader keeps being served the old book.
     */
    public function testReplacingTheSourceInvalidatesItsDerivatives(): void
    {
        $this->requireWebp();
        [$owner, $comic] = $this->createComicWithArchive();
        $this->loginAs($owner);
        $url = sprintf('/api/comics/%d/pages/1?variant=reader-small', $comic->getId());

        $this->browser()->request('GET', $url);
        $before = (string) $this->browser()->getResponse()->getContent();

        $this->replaceArchive($comic, 600, 900);

        $this->browser()->request('GET', $url);
        $after = (string) $this->browser()->getResponse()->getContent();

        self::assertNotSame($before, $after);
        self::assertSame(600, (int) (getimagesizefromstring($after)[0] ?? 0));
    }

    public function testTheManifestDescribesPagesWithoutRevealingTheArchive(): void
    {
        [$owner, $comic] = $this->createComicWithArchive();
        $this->loginAs($owner);

        $this->browser()->request('GET', sprintf('/api/comics/%d/pages', $comic->getId()));

        self::assertResponseIsSuccessful();
        $body = (string) $this->browser()->getResponse()->getContent();
        $payload = json_decode($body, true);

        self::assertSame(3, $payload['pageCount']);
        self::assertTrue($payload['complete']);
        self::assertSame(
            ['page' => 1, 'width' => self::SOURCE_WIDTH, 'height' => self::SOURCE_HEIGHT, 'aspectRatio' => 0.6667],
            $payload['pages'][0]
        );
        self::assertSame(1400, $payload['variants']['reader-medium']);

        // Nothing about where the comic lives, or what its pages are called
        // inside it, is any of a client's business.
        self::assertStringNotContainsString('page-01', $body);
        self::assertStringNotContainsString((string) $comic->getFilePath(), $body);
        self::assertStringNotContainsString('/var/www', $body);
    }

    /**
     * Geometry describes the page, not the variant that happened to ask for it:
     * #86's wide-page detection would see every page as a thumbnail otherwise.
     */
    public function testGeometryLearnedWhileServingAThumbnailDescribesTheSourcePage(): void
    {
        $this->requireWebp();
        [$owner, $comic] = $this->createComicWithArchive();
        $this->loginAs($owner);

        $this->browser()->request('GET', sprintf('/api/comics/%d/pages/2?variant=thumb', $comic->getId()));
        self::assertResponseIsSuccessful();

        $geometry = glob($this->cacheDirectory($comic).'/geometry-*.json') ?: [];
        self::assertCount(1, $geometry);

        $known = json_decode((string) file_get_contents($geometry[0]), true);
        self::assertSame(['width' => self::SOURCE_WIDTH, 'height' => self::SOURCE_HEIGHT], $known['2']);
    }

    public function testAnotherUserCannotReadAVariantOrTheManifest(): void
    {
        [, $comic] = $this->createComicWithArchive();
        $this->loginAs(UserFactory::createOne()->object());

        $this->browser()->request('GET', sprintf('/api/comics/%d/pages/1?variant=thumb', $comic->getId()));
        self::assertResponseStatusCodeSame(404);

        $this->browser()->request('GET', sprintf('/api/comics/%d/pages', $comic->getId()));
        self::assertResponseStatusCodeSame(404);
    }

    public function testTheManifestIsNotServedToAnonymousVisitors(): void
    {
        [, $comic] = $this->createComicWithArchive();

        $this->browser()->request('GET', sprintf('/api/comics/%d/pages', $comic->getId()));

        self::assertResponseStatusCodeSame(401);
    }

    private function requireWebp(): void
    {
        if (self::getContainer()->get(ComicPageDelivery::class)->deliveryFormat() !== ComicPageDelivery::FORMAT_WEBP) {
            self::markTestSkipped('This build of GD cannot write WebP, so pages are served unresized.');
        }
    }

    private function requestPage(Comic $comic, int $page, ?string $variant): string
    {
        $url = sprintf('/api/comics/%d/pages/%d', $comic->getId(), $page);
        if ($variant !== null) $url .= '?variant='.$variant;

        $this->browser()->request('GET', $url);
        self::assertResponseIsSuccessful();

        return (string) $this->browser()->getResponse()->getContent();
    }

    /** @return array{0: int, 1: int} */
    private function requestPageSize(Comic $comic, int $page, ?string $variant): array
    {
        $size = getimagesizefromstring($this->requestPage($comic, $page, $variant));
        self::assertIsArray($size);

        return [(int) $size[0], (int) $size[1]];
    }

    private function cacheDirectory(Comic $comic): string
    {
        return self::getContainer()->getParameter('page_cache_directory').'/'.$comic->getId();
    }

    /**
     * @return array{0: User, 1: Comic}
     */
    private function createComicWithArchive(): array
    {
        $owner = UserFactory::createOne()->object();
        $filename = 'variants-'.uniqid('', false).'.cbz';
        $comic = ComicFactory::createOne([
            'owner' => $owner,
            'filePath' => $filename,
            'pageCount' => 3,
        ])->object();

        $directory = self::getContainer()->getParameter('comics_directory').'/'.$owner->getId();
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            self::fail('Could not create the test comic directory.');
        }

        $path = $directory.'/'.$filename;
        $this->temporaryFiles[] = $path;
        $this->writeArchive($path, self::SOURCE_WIDTH, self::SOURCE_HEIGHT);

        // The page cache is keyed by comic id under a directory the database
        // rollback knows nothing about, and ids are reissued from the start of
        // every run. Left alone, a comic inherits the cached variants and
        // geometry files of whatever comic held its id in an earlier run — so a
        // test that counts what serving one page wrote would pass on a clean
        // checkout and fail on the fourth run, having proved nothing either
        // time. Claimed and emptied here, released in tearDown.
        $this->temporaryDirectories[] = $this->cacheDirectory($comic);
        $this->purgeCacheDirectory($comic);

        return [$owner, $comic];
    }

    private function purgeCacheDirectory(Comic $comic): void
    {
        foreach (glob($this->cacheDirectory($comic).'/*') ?: [] as $stale) {
            if (is_file($stale)) {
                unlink($stale);
            }
        }
    }

    private function replaceArchive(Comic $comic, int $width, int $height): void
    {
        $path = self::getContainer()->getParameter('comics_directory')
            .'/'.$comic->getOwner()->getId().'/'.$comic->getFilePath();

        $this->writeArchive($path, $width, $height);
        // A same-second replacement would otherwise be indistinguishable from
        // the original by modification time alone.
        touch($path, time() + 5);
        clearstatcache();
    }

    private function writeArchive(string $path, int $width, int $height): void
    {
        $zip = new \ZipArchive();
        if ($zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            self::fail('Could not create the test CBZ.');
        }

        $zip->addFromString('page-01.jpg', $this->drawPage($width, $height, 'jpeg'));
        $zip->addFromString('page-02.png', $this->drawPage($width, $height, 'png'));
        $zip->addFromString('page-03.webp', $this->drawPage($width, $height, 'webp'));
        $zip->close();
    }

    /**
     * A page with actual detail in it. A flat colour compresses to nothing at
     * any size, which would make "is this smaller than that" meaningless.
     */
    private function drawPage(int $width, int $height, string $format): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefilledrectangle($image, 0, 0, $width, $height, imagecolorallocate($image, 255, 255, 255));

        for ($i = 0; $i < 60; ++$i) {
            imagefilledellipse(
                $image,
                (int) (($i * 137) % $width),
                (int) (($i * 271) % $height),
                60,
                40,
                imagecolorallocate($image, ($i * 37) % 255, ($i * 91) % 255, ($i * 53) % 255)
            );
        }

        ob_start();
        match ($format) {
            'jpeg' => imagejpeg($image, null, 90),
            'png' => imagepng($image),
            'webp' => imagewebp($image, null, 90),
        };
        $bytes = (string) ob_get_clean();
        imagedestroy($image);

        return $bytes;
    }

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $path) {
            if (is_file($path)) unlink($path);
        }
        $this->temporaryFiles = [];

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
