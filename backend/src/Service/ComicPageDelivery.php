<?php

namespace App\Service;

use App\ComicSource\PageResult;
use Psr\Log\LoggerInterface;

/**
 * One delivery format for every page, whatever the comic was stored as, at
 * whatever size the variant asked for.
 *
 * The source providers hand back whatever the page happens to be — a JPEG out
 * of a CBZ, a PNG repacked from a PDF bitmap, a rendered page from Poppler.
 * Serving that straight through means the reader's bandwidth depends on how the
 * uploader happened to export their comic, and on a phone it means downloading
 * a 4000px scan to fill 400 CSS pixels. Everything is converted to WebP, bounded
 * to the variant's width, and cached by the derivative pipeline above.
 *
 * Every failure here is designed to end in a served page rather than an error.
 * A missing WebP encoder, an unreadable image, an image too large to decode:
 * each falls back to handing over exactly what the provider produced, because a
 * larger page is not a reason to show a reader nothing.
 */
final class ComicPageDelivery
{
    public const FORMAT_WEBP = 'webp';
    public const FORMAT_SOURCE = 'source';

    /**
     * Pages larger than this are handed over unconverted. A page that big is
     * either not a comic page or is one GD would spend real memory on, and the
     * reader is better served by the original than by a stalled request.
     */
    private const MAX_PIXELS = 80_000_000;

    private ?bool $canEncodeWebp = null;

    public function __construct(private readonly ?LoggerInterface $logger = null)
    {
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
                'summary' => 'Pages are delivered as WebP, resized to the requested variant, and cached after the first read.',
                'hint' => '',
            ];
        }

        return [
            'format' => self::FORMAT_SOURCE,
            'healthy' => false,
            'summary' => 'Pages are delivered in whatever format and size the comic stores them in.',
            'hint' => 'This PHP has GD without WebP support, so pages cannot be converted, resized or cached. Everything still works, but every page is larger and is re-read from the comic each time. Install a GD built with WebP (on Debian/Ubuntu, php-gd; from source, --with-webp).',
        ];
    }

    /**
     * Width and height from an image header, or null when the bytes are not an
     * image this server can read.
     *
     * getimagesizefromstring() returns false for anything it cannot parse, and
     * a zero dimension for a header it can parse but that describes nothing —
     * both mean the same thing to every caller here.
     *
     * @return array{int, int}|null
     */
    private function dimensions(string $bytes): ?array
    {
        $size = @getimagesizefromstring($bytes);
        if (!is_array($size) || $size[0] < 1 || $size[1] < 1) return null;

        return [$size[0], $size[1]];
    }

    /**
     * The dimensions of an encoded image, read from its header rather than by
     * decoding it. Null when the bytes are not an image this server can read.
     */
    public function measure(string $bytes, int $page): ?PageGeometry
    {
        $dimensions = $this->dimensions($bytes);
        if ($dimensions === null) return null;

        return new PageGeometry($page, $dimensions[0], $dimensions[1]);
    }

    /**
     * The page as WebP, no wider than $maxWidth, or null when this server
     * cannot produce that and the source has to be served instead.
     *
     * Never upscales. A variant is a ceiling, not a target: a 600px page
     * requested as reader-large is already the best this comic has, and
     * stretching it would cost bytes to deliver exactly the same detail.
     */
    public function encode(PageResult $source, ?int $maxWidth, int $quality): ?PageResult
    {
        $dimensions = $this->dimensions($source->content);
        if ($dimensions === null) return null;

        [$width, $height] = $dimensions;
        if ($width * $height > self::MAX_PIXELS) return null;

        $targetWidth = $maxWidth !== null && $width > $maxWidth ? $maxWidth : $width;
        if ($targetWidth === $width && $source->mimeType === 'image/webp') return $source;
        if (!$this->webpAvailable()) return null;

        $decoded = @imagecreatefromstring($source->content);
        if ($decoded === false) return null;

        // Flattening is deliberate: comics have no meaningful transparency and
        // alpha costs bytes on every page. It happens before the resample
        // because a palette image resamples into visible banding.
        imagepalettetotruecolor($decoded);
        $image = $this->scale($decoded, $width, $height, $targetWidth);

        // The buffer is opened outside the try and closed in the finally, so a
        // ValueError out of imagewebp cannot leave it open — an abandoned
        // buffer would mix raw GD output into the next HTTP response.
        ob_start();

        try {
            $encoded = @imagewebp($image, null, $quality);
            $bytes = (string) ob_get_contents();

            if ($encoded === false || $bytes === '') return null;

            return new PageResult($bytes, 'image/webp');
        } catch (\Throwable $exception) {
            $this->logger?->debug('Page could not be converted to WebP.', ['reason' => $exception->getMessage()]);
            return null;
        } finally {
            ob_end_clean();
            if ($image !== $decoded) imagedestroy($image);
            imagedestroy($decoded);
        }
    }

    /**
     * The image at the target width, or the same image back when it is already
     * narrow enough or cannot be resampled — a variant that could not be made
     * smaller is still a page worth serving.
     */
    private function scale(\GdImage $image, int $width, int $height, int $targetWidth): \GdImage
    {
        if ($targetWidth >= $width) return $image;

        $targetHeight = max(1, (int) round($height * ($targetWidth / $width)));
        $resized = @imagecreatetruecolor($targetWidth, $targetHeight);
        if ($resized === false) return $image;

        if (!@imagecopyresampled($resized, $image, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height)) {
            imagedestroy($resized);
            return $image;
        }

        return $resized;
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
