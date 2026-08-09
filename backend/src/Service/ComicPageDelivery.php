<?php

namespace App\Service;

use App\ComicSource\PageResult;
use App\Entity\Comic;
use Psr\Log\LoggerInterface;

/**
 * One delivery format for every page, whatever the comic was stored as.
 *
 * The source providers hand back whatever the page happens to be — a JPEG out
 * of a CBZ, a PNG repacked from a PDF bitmap, a rendered page from Poppler.
 * Serving that straight through means the reader's bandwidth depends on how
 * the uploader happened to export their comic. Everything is converted to WebP
 * once, cached, and served from the cache after that.
 *
 * Every failure here is designed to end in a served page rather than an error.
 * A missing WebP encoder, an unreadable image, a cache directory that cannot be
 * written: each falls back to handing over exactly what the provider produced,
 * because a slightly larger page is not a reason to show a reader nothing.
 */
final class ComicPageDelivery
{
    public const FORMAT_WEBP = 'webp';
    public const FORMAT_SOURCE = 'source';

    private const QUALITY = 82;

    /**
     * Pages larger than this are handed over unconverted. A page that big is
     * either not a comic page or is one GD would spend real memory on, and the
     * reader is better served by the original than by a stalled request.
     */
    private const MAX_PIXELS = 80_000_000;

    private ?bool $canEncodeWebp = null;

    public function __construct(
        private readonly ComicService $comicService,
        private readonly ComicPageCache $cache,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * The page as the reader should receive it, plus the format actually used
     * so the caller can vary its cache validators on it.
     *
     * @return array{0: PageResult, 1: string}
     */
    public function deliver(Comic $comic, int $page): array
    {
        $identifier = $comic->getId();
        $fingerprint = $this->fingerprint($comic);

        if ($identifier !== null && $fingerprint !== null) {
            $cached = $this->cache->read($identifier, $page, $fingerprint);
            if ($cached !== null) {
                try {
                    return [new PageResult($cached, 'image/webp'), self::FORMAT_WEBP];
                } catch (\Throwable) {
                    // A truncated entry is not worth diagnosing; drop and redo.
                    $this->cache->forget($identifier, $page, $fingerprint);
                }
            }
        }

        $source = $this->comicService->readPage($comic, $page);

        $webp = $this->toWebp($source);
        if ($webp === null) return [$source, self::FORMAT_SOURCE];

        if ($identifier !== null && $fingerprint !== null) {
            $this->cache->write($identifier, $page, $fingerprint, $webp->content);
        }

        return [$webp, self::FORMAT_WEBP];
    }

    /**
     * Identifies the bytes behind a comic, so replacing its file cannot serve
     * pages generated from the previous one.
     */
    private function fingerprint(Comic $comic): ?string
    {
        $source = $this->comicService->locateComicSource($comic);
        if ($source === null) return null;

        return hash('xxh128', $source.'|'.(@filemtime($source) ?: 0).'|'.(@filesize($source) ?: 0));
    }

    /**
     * The format pages will be served in on this server. Cheap enough to ask
     * before deciding a request is unmodified, which is the point: it belongs
     * in the cache validator.
     */
    public function deliveryFormat(): string
    {
        return $this->webpAvailable() ? self::FORMAT_WEBP : self::FORMAT_SOURCE;
    }

    /**
     * What an administrator needs to know about page delivery on this server.
     *
     * Reported rather than enforced: serving source bytes is a working
     * installation, just a more expensive one for every reader on every page,
     * which is worth saying out loud on a host nobody configured for it.
     *
     * @return array{format: string, healthy: bool, summary: string, hint: string}
     */
    public function describe(): array
    {
        if ($this->webpAvailable()) {
            return [
                'format' => self::FORMAT_WEBP,
                'healthy' => true,
                'summary' => 'Pages are delivered as WebP and cached after the first read.',
                'hint' => '',
            ];
        }

        return [
            'format' => self::FORMAT_SOURCE,
            'healthy' => false,
            'summary' => 'Pages are delivered in whatever format the comic stores them in.',
            'hint' => 'This PHP has GD without WebP support, so pages cannot be converted or cached. Everything still works, but every page is larger and is re-read from the comic each time. Install a GD built with WebP (on Debian/Ubuntu, php-gd; from source, --with-webp).',
        ];
    }

    private function toWebp(PageResult $source): ?PageResult
    {
        if ($source->mimeType === 'image/webp') return $source;
        if (!$this->webpAvailable()) return null;

        $size = @getimagesizefromstring($source->content);
        if (!is_array($size) || $size[0] < 1 || $size[1] < 1) return null;
        if ($size[0] * $size[1] > self::MAX_PIXELS) return null;

        $image = @imagecreatefromstring($source->content);
        if ($image === false) return null;

        // The buffer is opened outside the try and closed in the finally, so a
        // ValueError out of imagewebp cannot leave it open — an abandoned
        // buffer would mix raw GD output into the next HTTP response.
        ob_start();

        try {
            // Flattening is deliberate: comics have no meaningful transparency
            // and alpha costs bytes on every page.
            imagepalettetotruecolor($image);

            $encoded = @imagewebp($image, null, self::QUALITY);
            $bytes = (string) ob_get_contents();

            if ($encoded === false || $bytes === '') return null;

            return new PageResult($bytes, 'image/webp');
        } catch (\Throwable $exception) {
            $this->logger?->debug('Page could not be converted to WebP.', ['reason' => $exception->getMessage()]);
            return null;
        } finally {
            ob_end_clean();
            imagedestroy($image);
        }
    }

    private function webpAvailable(): bool
    {
        if ($this->canEncodeWebp !== null) return $this->canEncodeWebp;

        $available = \function_exists('imagewebp') && \function_exists('imagecreatefromstring');
        if ($available && \function_exists('gd_info')) {
            $info = gd_info();
            $available = ($info['WebP Support'] ?? false) === true;
        }

        if (!$available) {
            $this->logger?->warning('GD cannot write WebP, so comic pages are served in their source format.');
        }

        return $this->canEncodeWebp = $available;
    }

}
