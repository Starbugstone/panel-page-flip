<?php

namespace App\Service;

use App\ComicSource\PageResult;
use App\Entity\Comic;
use App\Enum\PageVariant;
use Psr\Log\LoggerInterface;
use Symfony\Component\Lock\LockFactory;

/**
 * One pipeline that turns a comic's source pages into the bounded set of images
 * the reader actually displays, and remembers what shape those pages are.
 *
 * It knows nothing about CBZ, CBR, CB7, CBT or PDF. Pages come from the source
 * provider factory through ComicService; everything here is about size, cache
 * and geometry. Derivatives are rebuildable server cache, never canonical user
 * files, so nothing produced here counts towards a storage quota and every
 * failure ends in a served page rather than an error.
 */
final class PageDerivativeService
{
    /**
     * Bump to invalidate every derivative on the server.
     *
     * It is folded into the fingerprint, so a change to variant widths or
     * encoder quality cannot leave last week's bytes being served under this
     * week's URL — and the ETag moves with it, so browsers re-ask too.
     */
    public const RENDER_VERSION = 1;

    /**
     * How many pages one manifest request may open to learn their dimensions,
     * and how long it may spend doing it.
     *
     * A whole book cannot be inspected synchronously — for a PDF that needs
     * rendering, a single page can cost seconds — so a manifest resolves a few
     * pages at a time and says whether it is complete. Serving pages fills the
     * rest in for free.
     */
    public const MANIFEST_RESOLVE_LIMIT = 8;
    private const MANIFEST_RESOLVE_BUDGET_SECONDS = 2.0;

    private const LOCK_TTL_SECONDS = 45.0;
    private const WAIT_POLL_MICROSECONDS = 50_000;

    public function __construct(
        private readonly ComicService $comicService,
        private readonly ComicPageCache $cache,
        private readonly ComicPageDelivery $encoder,
        private readonly LockFactory $lockFactory,
        private readonly ?LoggerInterface $logger = null,
        /**
         * How long a request waits for another one already generating the same
         * derivative. Long enough to cover an ordinary resize, short enough
         * that a wedged generator does not hold a reader indefinitely.
         */
        private readonly float $singleFlightWaitSeconds = 5.0,
    ) {
    }

    /**
     * The page at the requested size, from cache when it is there and generated
     * once when it is not.
     */
    public function getOrCreate(Comic $comic, int $page, PageVariant $variant): DerivedPage
    {
        $identifier = $comic->getId();
        $fingerprint = $this->fingerprint($comic);
        $cacheable = $identifier !== null && $fingerprint !== null;

        if ($cacheable) {
            $cached = $this->readCached($identifier, $page, $fingerprint, $variant);
            if ($cached !== null) return $cached;
        }

        // One generation per key. A hundred readers arriving on the same page of
        // the same comic at the same moment must cost one resize, not a hundred.
        $lock = null;
        if ($cacheable) {
            $lock = $this->lockFactory->createLock(
                sprintf('comic-page-derivative-%s-%d-%s', $fingerprint, $page, $variant->value),
                self::LOCK_TTL_SECONDS
            );

            if (!$lock->acquire()) {
                $awaited = $this->awaitCached($identifier, $page, $fingerprint, $variant);
                if ($awaited !== null) return $awaited;

                // The holder is taking longer than a reader should wait, or died
                // holding it. Generating a second copy wastes work; refusing the
                // page wastes the reader's time, which is worse.
                $lock = null;
            }
        }

        try {
            return $this->generate($comic, $page, $variant, $cacheable ? $identifier : null, $fingerprint);
        } finally {
            $lock?->release();
        }
    }

    /**
     * The shape of one page, measured if it has not been seen before.
     *
     * Null when the page cannot be read at all, which is normal for a damaged
     * comic and is never a reason to fail the request that asked.
     */
    public function getPageInfo(Comic $comic, int $page): ?PageGeometry
    {
        $identifier = $comic->getId();
        $fingerprint = $this->fingerprint($comic);

        if ($identifier !== null && $fingerprint !== null) {
            $known = $this->cache->readGeometry($identifier, $fingerprint)[$page] ?? null;
            if ($known !== null) return new PageGeometry($page, $known['width'], $known['height']);
        }

        return $this->measurePage($comic, $page, $identifier, $fingerprint);
    }

    /**
     * Everything known about this comic's page geometry, plus a bounded amount
     * of new measuring starting at $from.
     *
     * @return array{pages: list<array{page: int, width: int, height: int, aspectRatio: float}>, complete: bool}
     */
    public function describePages(Comic $comic, int $from, int $limit = self::MANIFEST_RESOLVE_LIMIT): array
    {
        $pageCount = $comic->getPageCount() ?? 0;
        $identifier = $comic->getId();
        $fingerprint = $this->fingerprint($comic);

        $known = $identifier !== null && $fingerprint !== null
            ? $this->cache->readGeometry($identifier, $fingerprint)
            : [];

        $deadline = microtime(true) + self::MANIFEST_RESOLVE_BUDGET_SECONDS;
        $resolved = 0;

        for ($page = max(1, $from); $page <= $pageCount && $resolved < $limit; ++$page) {
            if (isset($known[$page])) continue;
            if (microtime(true) >= $deadline) break;

            ++$resolved;
            $geometry = $this->measurePage($comic, $page, $identifier, $fingerprint);
            if ($geometry !== null) $known[$page] = ['width' => $geometry->width, 'height' => $geometry->height];
        }

        ksort($known);

        $pages = [];
        foreach ($known as $page => $size) {
            if ($page < 1 || $page > $pageCount) continue;
            $pages[] = (new PageGeometry($page, $size['width'], $size['height']))->toArray();
        }

        return ['pages' => $pages, 'complete' => $pageCount > 0 && count($pages) === $pageCount];
    }

    /**
     * Throw away everything generated from this comic's current source.
     *
     * Metadata edits deliberately do not come through here: retitling a comic
     * does not change a pixel, and regenerating a 300-page book for it would be
     * pure waste.
     */
    public function invalidateComic(Comic $comic): void
    {
        $identifier = $comic->getId();
        if ($identifier !== null) $this->cache->purge($identifier);
    }

    /**
     * What a cache validator for this page has to include, so that a browser
     * cannot revalidate one variant's bytes as another's, or keep a copy made
     * by an encoder this server no longer has.
     */
    public function validatorSignature(PageVariant $variant): string
    {
        return $variant->value.'|'.self::RENDER_VERSION.'|'.$this->encoder->deliveryFormat();
    }

    private function readCached(int $comicId, int $page, string $fingerprint, PageVariant $variant): ?DerivedPage
    {
        $cached = $this->cache->read($comicId, $page, $fingerprint, $variant);
        if ($cached === null) return null;

        try {
            $geometry = $this->cache->readGeometry($comicId, $fingerprint)[$page] ?? null;

            return new DerivedPage(
                new PageResult($cached, 'image/webp'),
                $variant,
                ComicPageDelivery::FORMAT_WEBP,
                $geometry === null ? null : new PageGeometry($page, $geometry['width'], $geometry['height']),
            );
        } catch (\Throwable) {
            // A truncated entry is not worth diagnosing; drop it and redo.
            $this->cache->forget($comicId, $page, $fingerprint, $variant);

            return null;
        }
    }

    /** Wait for whoever holds the lock to publish the derivative we both want. */
    private function awaitCached(int $comicId, int $page, string $fingerprint, PageVariant $variant): ?DerivedPage
    {
        $deadline = microtime(true) + $this->singleFlightWaitSeconds;

        while (microtime(true) < $deadline) {
            usleep(self::WAIT_POLL_MICROSECONDS);

            $cached = $this->readCached($comicId, $page, $fingerprint, $variant);
            if ($cached !== null) return $cached;
        }

        $this->logger?->info('Waited out another request generating a comic page.', [
            'comic_id' => $comicId,
            'page' => $page,
            'variant' => $variant->value,
        ]);

        return null;
    }

    private function generate(
        Comic $comic,
        int $page,
        PageVariant $variant,
        ?int $comicId,
        ?string $fingerprint,
    ): DerivedPage {
        // The width is a hint, not an instruction: an archive hands back its own
        // entry regardless, while a PDF page that has to be drawn is drawn near
        // the size asked for rather than at full resolution and shrunk after.
        $source = $this->comicService->readPage($comic, $page, $variant->maxWidth());

        $geometry = $source->isSourceSized ? $this->encoder->measure($source->content, $page) : null;
        if ($geometry !== null && $comicId !== null && $fingerprint !== null) {
            $this->cache->rememberGeometry($comicId, $fingerprint, $geometry);
        }

        $encoded = $this->encoder->encode($source, $variant->maxWidth(), $variant->quality());
        if ($encoded === null) {
            return new DerivedPage($source, $variant, ComicPageDelivery::FORMAT_SOURCE, $geometry);
        }

        if ($comicId !== null && $fingerprint !== null) {
            $this->cache->write($comicId, $page, $fingerprint, $variant, $encoded->content);
        }

        return new DerivedPage($encoded, $variant, ComicPageDelivery::FORMAT_WEBP, $geometry);
    }

    /**
     * Measure a page by reading it, and remember the answer.
     *
     * No width hint is passed: a rendered PDF page comes back at whatever size
     * it was drawn, and geometry has to describe the page as the reader meets it
     * at full size rather than as some variant happened to ask for it.
     */
    private function measurePage(Comic $comic, int $page, ?int $comicId, ?string $fingerprint): ?PageGeometry
    {
        try {
            $source = $this->comicService->readPage($comic, $page);
            $geometry = $this->encoder->measure($source->content, $page);
        } catch (\Throwable $exception) {
            $this->logger?->debug('A comic page could not be measured.', [
                'comic_id' => $comicId,
                'page' => $page,
                'reason' => $exception->getMessage(),
            ]);

            return null;
        }

        if ($geometry !== null && $comicId !== null && $fingerprint !== null) {
            $this->cache->rememberGeometry($comicId, $fingerprint, $geometry);
        }

        return $geometry;
    }

    /**
     * Identifies the bytes behind a comic and the pipeline that renders them, so
     * replacing its file — or changing how pages are produced — cannot serve
     * derivatives made from the previous one.
     */
    private function fingerprint(Comic $comic): ?string
    {
        $source = $this->comicService->locateComicSource($comic);
        if ($source === null) return null;

        return hash(
            'xxh128',
            $source.'|'.(@filemtime($source) ?: 0).'|'.(@filesize($source) ?: 0).'|'.self::RENDER_VERSION
        );
    }
}
