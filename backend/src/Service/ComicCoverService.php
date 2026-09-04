<?php

declare(strict_types=1);

namespace App\Service;

use App\ComicSource\ComicSourceLimits;
use App\ComicSource\PageResult;
use App\Enum\PageVariant;
use Symfony\Component\Lock\LockFactory;

/** Resize stored covers without reopening the archive or changing the original file. */
final class ComicCoverService
{
    public function __construct(
        private readonly ComicPageCache $cache,
        private readonly ComicPageDelivery $encoder,
        private readonly LockFactory $locks,
    ) {
    }

    public function getOrCreate(int $comicId, string $source): string
    {
        $variant = PageVariant::Small;
        $fingerprint = hash('xxh128', 'cover|'.$source.'|'.filemtime($source).'|'.filesize($source).'|'.PageDerivativeService::RENDER_VERSION);
        $cached = $this->cache->find($comicId, 1, $fingerprint, $variant);
        if ($cached !== null) return $cached;

        // A busy generator must not hold up the grid. The original remains a
        // valid cover while another request publishes the smaller rendition.
        $lock = $this->locks->createLock('comic-cover-'.$fingerprint, 45);
        if (!$lock->acquire()) return $source;

        try {
            $cached = $this->cache->find($comicId, 1, $fingerprint, $variant);
            if ($cached !== null) return $cached;

            // Check before allocating, including for covers from older imports.
            $size = filesize($source);
            if ($size === false || $size < 1 || $size > ComicSourceLimits::MAX_PAGE_BYTES) return $source;
            $bytes = @file_get_contents($source, length: ComicSourceLimits::MAX_PAGE_BYTES + 1);
            if ($bytes === false || $bytes === '' || strlen($bytes) > ComicSourceLimits::MAX_PAGE_BYTES) return $source;

            $dimensions = @getimagesizefromstring($bytes);
            if ($dimensions === false) return $source;
            $encoded = $this->encoder->encode(new PageResult($bytes, $dimensions['mime']), $variant->maxWidth(), $variant->quality());
            if ($encoded === null) return $source;

            $this->cache->write($comicId, 1, $fingerprint, $variant, $encoded->content);

            return $this->cache->find($comicId, 1, $fingerprint, $variant) ?? $source;
        } finally {
            $lock->release();
        }
    }
}
