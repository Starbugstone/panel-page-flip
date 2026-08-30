<?php

namespace App\Tests\Functional\Controller;

use App\Entity\Comic;
use App\Entity\User;
use App\ComicSource\ZipPageProvider;
use App\Service\ComicService;
use App\Tests\Factory\ComicFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Functional\AbstractApiTestCase;

/**
 * Which entries of an archive count as pages, and in what order.
 *
 * There used to be two answers. ComicService skipped `__MACOSX` resource forks
 * and dot-files when it counted pages at upload time; the page-serving endpoint
 * carried its own copy of the loop that skipped neither. So an archive zipped on
 * a Mac stored one page count and served a different, longer list — every page
 * after the first fork was the wrong image, and the last real page was
 * unreachable behind entries that are not pages at all.
 */
final class ComicPageIndexTest extends AbstractApiTestCase
{
    /** @var list<string> */
    private array $temporaryFiles = [];

    public function testResourceForksAreNotServedAsPages(): void
    {
        [$owner, $comic] = $this->createComicWithNoisyArchive();
        $this->loginAs($owner);

        // page-01 and page-02 are the only real pages. If the noise counted,
        // "._page-01.jpg" would sort first and page 1 would serve a fork.
        $this->browser()->request('GET', sprintf('/api/comics/%d/pages/1', $comic->getId()));
        self::assertResponseIsSuccessful();
        self::assertSame($this->pageBytes('one'), $this->browser()->getResponse()->getContent());

        $this->browser()->request('GET', sprintf('/api/comics/%d/pages/2', $comic->getId()));
        self::assertResponseIsSuccessful();
        self::assertSame($this->pageBytes('two'), $this->browser()->getResponse()->getContent());
    }

    /**
     * The stored page count and the served page list have to agree, or the
     * reader either stops short of the comic or runs off the end of it.
     */
    public function testStoredPageCountMatchesTheServedPageList(): void
    {
        [$owner, $comic] = $this->createComicWithNoisyArchive();
        $this->loginAs($owner);

        $comicService = self::getContainer()->get(ComicService::class);
        $archivePath = $comicService->locateComicSource($comic);
        self::assertNotNull($archivePath);

        $provider = self::getContainer()->get(ZipPageProvider::class);
        self::assertCount($comic->getPageCount(), $provider->pageIndex($archivePath));

        // One past the end is a miss, not a resource fork.
        $this->browser()->request(
            'GET',
            sprintf('/api/comics/%d/pages/%d', $comic->getId(), $comic->getPageCount() + 1)
        );
        self::assertResponseStatusCodeSame(400);
    }

    /**
     * The index is cached against the file, so an archive replaced underneath it
     * must produce a new answer rather than the remembered one.
     */
    public function testTheCachedIndexFollowsTheArchive(): void
    {
        [, $comic] = $this->createComicWithNoisyArchive();

        $comicService = self::getContainer()->get(ComicService::class);
        $archivePath = $comicService->locateComicSource($comic);
        self::assertNotNull($archivePath);
        $provider = self::getContainer()->get(ZipPageProvider::class);
        self::assertCount(2, $provider->pageIndex($archivePath));

        $zip = new \ZipArchive();
        self::assertTrue($zip->open($archivePath) === true);
        $zip->addFromString('page-03.jpg', $this->pageBytes('three'));
        $zip->close();
        // The key is path + mtime + size; a same-second rewrite would otherwise
        // be indistinguishable from the original.
        touch($archivePath, time() + 5);
        clearstatcache(true, $archivePath);

        self::assertCount(3, $provider->pageIndex($archivePath));
    }

    /**
     * @return array{0: User, 1: Comic}
     */
    private function createComicWithNoisyArchive(): array
    {
        $owner = UserFactory::createOne();
        $filename = 'page-index-' . uniqid('', false) . '.cbz';
        $comic = ComicFactory::createOne([
            'owner' => $owner,
            'filePath' => $filename,
            'pageCount' => 2,
        ]);

        $directory = self::getContainer()->getParameter('comics_directory') . '/' . $owner->getId();
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            self::fail('Could not create the test comic directory.');
        }
        $archivePath = $directory . '/' . $filename;
        $this->temporaryFiles[] = $archivePath;

        $zip = new \ZipArchive();
        if ($zip->open($archivePath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            self::fail('Could not create the test CBZ.');
        }
        // Exactly what a Mac's Archive Utility leaves behind, and exactly what
        // sorts ahead of the real pages.
        $zip->addFromString('__MACOSX/._page-01.jpg', 'resource fork, not a page');
        $zip->addFromString('._page-01.jpg', 'dot file, not a page');
        $zip->addFromString('page-01.jpg', $this->pageBytes('one'));
        $zip->addFromString('page-02.jpg', $this->pageBytes('two'));
        $zip->close();

        return [$owner, $comic];
    }

    /** Distinguishable bytes, so a wrong page is a failed assertion and not a coincidence. */
    private function pageBytes(string $marker): string
    {
        return "\xFF\xD8\xFF\xE0" . $marker;
    }

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        $this->temporaryFiles = [];

        parent::tearDown();
    }
}
