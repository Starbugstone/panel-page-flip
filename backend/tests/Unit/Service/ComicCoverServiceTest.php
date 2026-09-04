<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\ComicSource\ComicSourceLimits;
use App\Service\ComicCoverService;
use App\Service\ComicPageCache;
use App\Service\ComicPageDelivery;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

final class ComicCoverServiceTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/comic-covers-'.bin2hex(random_bytes(8));
        mkdir($this->directory);
    }

    public function testCachesACompactCoverWithoutChangingTheSourceAndPurgesItWithTheComic(): void
    {
        $source = $this->directory.'/cover.png';
        $image = imagecreatetruecolor(1600, 2400);
        self::assertNotFalse($image);
        imagepng($image, $source);
        imagedestroy($image);
        $original = hash_file('sha256', $source);
        $cache = new ComicPageCache($this->directory);
        $service = new ComicCoverService($cache, new ComicPageDelivery(), new LockFactory(new InMemoryStore()));

        $first = $service->getOrCreate(7, $source);
        self::assertNotSame($source, $first);
        self::assertSame($first, $service->getOrCreate(7, $source));
        self::assertSame($original, hash_file('sha256', $source));
        $dimensions = getimagesize($first);
        self::assertIsArray($dimensions);
        self::assertSame([800, 1200], [$dimensions[0], $dimensions[1]]);

        // A replacement cover must not inherit a previous rendition.
        touch($source, time() + 2);
        clearstatcache(true, $source);
        self::assertNotSame($first, $service->getOrCreate(7, $source));
        $cache->purge(7);
        self::assertFileDoesNotExist($first);
        self::assertFileExists($source);
    }

    public function testUnsafeOrUnreadableCoversFallBackToStreamingTheSource(): void
    {
        $source = $this->directory.'/cover.png';
        $service = new ComicCoverService(new ComicPageCache($this->directory), new ComicPageDelivery(), new LockFactory(new InMemoryStore()));

        file_put_contents($source, 'not an image');
        self::assertSame($source, $service->getOrCreate(7, $source));

        // A sparse oversized file exercises the pre-allocation bound.
        $file = fopen($source, 'w');
        self::assertIsResource($file);
        ftruncate($file, ComicSourceLimits::MAX_PAGE_BYTES + 1);
        fclose($file);
        clearstatcache(true, $source);
        self::assertSame($source, $service->getOrCreate(7, $source));
        self::assertDirectoryDoesNotExist($this->directory.'/7');
    }

    protected function tearDown(): void
    {
        (new ComicPageCache($this->directory))->purge(7);
        foreach (glob($this->directory.'/*') ?: [] as $file) unlink($file);
        rmdir($this->directory);
    }
}
