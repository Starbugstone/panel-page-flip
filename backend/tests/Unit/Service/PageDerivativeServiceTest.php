<?php

namespace App\Tests\Unit\Service;

use App\ComicSource\PageResult;
use App\Entity\Comic;
use App\Enum\PageVariant;
use App\Service\ComicPageCache;
use App\Service\ComicPageDelivery;
use App\Service\ComicService;
use App\Service\PageDerivativeService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

/**
 * What happens when more than one reader wants the same page at the same
 * moment, and what the pipeline remembers afterwards.
 *
 * A hundred simultaneous misses on the same page must cost one resize. The
 * expensive part is decoding a full-size scan, so the failure mode this guards
 * against is not a wasted file — it is a hundred decodes of the same 20-megapixel
 * image landing on one server at once.
 */
final class PageDerivativeServiceTest extends TestCase
{
    private string $cacheDirectory;
    private string $sourcePath;

    protected function setUp(): void
    {
        $this->cacheDirectory = sys_get_temp_dir().'/page-derivatives-'.bin2hex(random_bytes(6));
        mkdir($this->cacheDirectory, 0775, true);

        // Only ever stat'ed here: the source is read through the mocked
        // ComicService, and this file exists so the fingerprint has something
        // real to describe.
        $this->sourcePath = $this->cacheDirectory.'/source.cbz';
        file_put_contents($this->sourcePath, 'not really an archive');
    }

    public function testAWaitingRequestUsesTheDerivativeTheHolderProduced(): void
    {
        $comicService = $this->createMock(ComicService::class);
        $comicService->method('locateComicSource')->willReturn($this->sourcePath);
        $comicService->expects(self::never())->method('readPage');

        $cache = new ComicPageCache($this->cacheDirectory);
        $locks = new LockFactory(new InMemoryStore());
        $service = $this->service($comicService, $cache, $locks);
        $comic = $this->comic();

        // Another request is midway through generating this exact derivative,
        // and has just published it.
        $holder = $locks->createLock($this->lockKey(1, PageVariant::Medium));
        self::assertTrue($holder->acquire());
        $cache->write(7, 1, $this->fingerprint(), PageVariant::Medium, 'pretend-webp-bytes');

        $derived = $service->getOrCreate($comic, 1, PageVariant::Medium);

        self::assertSame('pretend-webp-bytes', $derived->page->content);
        self::assertSame(ComicPageDelivery::FORMAT_WEBP, $derived->format);
    }

    /**
     * A generator that dies holding its lock, or takes longer than a reader is
     * willing to wait for, must not turn into a page that never arrives.
     */
    public function testARequestThatWaitsTooLongGeneratesThePageItself(): void
    {
        $comicService = $this->createMock(ComicService::class);
        $comicService->method('locateComicSource')->willReturn($this->sourcePath);
        $comicService->expects(self::once())->method('readPage')->willReturn($this->page());

        $locks = new LockFactory(new InMemoryStore());
        $service = $this->service($comicService, new ComicPageCache($this->cacheDirectory), $locks);

        $abandoned = $locks->createLock($this->lockKey(1, PageVariant::Medium));
        self::assertTrue($abandoned->acquire());

        $derived = $service->getOrCreate($this->comic(), 1, PageVariant::Medium);

        self::assertNotSame('', $derived->page->content);
    }

    public function testTheSourceIsReadOnceForAPageThatIsAskedForTwice(): void
    {
        $comicService = $this->createMock(ComicService::class);
        $comicService->method('locateComicSource')->willReturn($this->sourcePath);
        $comicService->expects(self::once())->method('readPage')->willReturn($this->page());

        $service = $this->service($comicService, new ComicPageCache($this->cacheDirectory), new LockFactory(new InMemoryStore()));
        $comic = $this->comic();

        $first = $service->getOrCreate($comic, 1, PageVariant::Small);
        $second = $service->getOrCreate($comic, 1, PageVariant::Small);

        self::assertSame($first->page->content, $second->page->content);
    }

    public function testGeometryIsMeasuredOnceAndRememberedAfterwards(): void
    {
        $comicService = $this->createMock(ComicService::class);
        $comicService->method('locateComicSource')->willReturn($this->sourcePath);
        $comicService->expects(self::once())->method('readPage')->willReturn($this->page(400, 600));

        $service = $this->service($comicService, new ComicPageCache($this->cacheDirectory), new LockFactory(new InMemoryStore()));
        $comic = $this->comic();

        $first = $service->getPageInfo($comic, 1);
        $second = $service->getPageInfo($comic, 1);

        self::assertNotNull($first);
        self::assertSame([400, 600], [$first->width, $first->height]);
        self::assertSame(0.6667, $first->aspectRatio());
        self::assertEquals($first, $second);
    }

    /**
     * A drawn page's dimensions describe the render, not the page. Recording
     * them would tell #86's wide-page detection that every page of a PDF is
     * exactly as wide as whatever variant happened to be asked for first.
     */
    public function testARenderedPageDoesNotHaveItsRenderSizeRecordedAsGeometry(): void
    {
        $comicService = $this->createMock(ComicService::class);
        $comicService->method('locateComicSource')->willReturn($this->sourcePath);
        $comicService->method('readPage')->willReturn($this->page(280, 420, false));

        $cache = new ComicPageCache($this->cacheDirectory);
        $service = $this->service($comicService, $cache, new LockFactory(new InMemoryStore()));

        $service->getOrCreate($this->comic(), 1, PageVariant::Thumb);

        self::assertSame([], $cache->readGeometry(7, $this->fingerprint()));
    }

    public function testInvalidatingAComicDropsItsDerivativesAndNothingElse(): void
    {
        $comicService = $this->createMock(ComicService::class);
        $comicService->method('locateComicSource')->willReturn($this->sourcePath);
        $comicService->method('readPage')->willReturn($this->page());

        $cache = new ComicPageCache($this->cacheDirectory);
        $service = $this->service($comicService, $cache, new LockFactory(new InMemoryStore()));
        $comic = $this->comic();

        $service->getOrCreate($comic, 1, PageVariant::Small);
        $service->invalidateComic($comic);

        self::assertSame([], glob($this->cacheDirectory.'/7/*') ?: []);
        self::assertFileExists($this->sourcePath);
    }

    private function service(ComicService $comicService, ComicPageCache $cache, LockFactory $locks): PageDerivativeService
    {
        // A tenth of the production wait: these tests are about which branch is
        // taken, not about how patient a real reader should be.
        return new PageDerivativeService($comicService, $cache, new ComicPageDelivery(), $locks, null, 0.5);
    }

    private function comic(): Comic
    {
        $comic = new Comic();
        $comic->setPageCount(3);

        $id = new \ReflectionProperty(Comic::class, 'id');
        $id->setAccessible(true);
        $id->setValue($comic, 7);

        return $comic;
    }

    /** The key the service takes for this page, spelled out so a change to it fails here. */
    private function lockKey(int $page, PageVariant $variant): string
    {
        return sprintf('comic-page-derivative-%s-%d-%s', $this->fingerprint(), $page, $variant->value);
    }

    private function fingerprint(): string
    {
        clearstatcache();

        return hash(
            'xxh128',
            $this->sourcePath.'|'.filemtime($this->sourcePath).'|'.filesize($this->sourcePath)
                .'|'.PageDerivativeService::RENDER_VERSION
        );
    }

    private function page(int $width = 1200, int $height = 1800, bool $isSourceSized = true): PageResult
    {
        $image = imagecreatetruecolor($width, $height);
        imagefilledrectangle($image, 0, 0, $width, $height, imagecolorallocate($image, 20, 90, 160));
        ob_start();
        imagejpeg($image, null, 85);
        $bytes = (string) ob_get_clean();
        imagedestroy($image);

        return new PageResult($bytes, 'image/jpeg', $isSourceSized);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->cacheDirectory.'/*') ?: [] as $entry) {
            if (is_dir($entry)) {
                foreach (glob($entry.'/*') ?: [] as $file) unlink($file);
                rmdir($entry);
                continue;
            }
            unlink($entry);
        }
        rmdir($this->cacheDirectory);
    }
}
