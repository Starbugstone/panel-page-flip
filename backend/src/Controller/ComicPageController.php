<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Comic;
use App\Enum\PageVariant;
use App\Security\ComicAccess;
use App\Security\ComicNotAccessibleException;
use App\Security\Voter\ComicVoter;
use App\Service\ComicService;
use App\Service\ComicCoverService;
use App\Service\PageDerivativeService;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpKernel\EventListener\AbstractSessionListener;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Serving the bytes: pages, covers and the archive itself.
 *
 * These share what none of the metadata routes need — a caching policy, ETags
 * and conditional requests, range support, and the rule that an image response
 * must never be allowed into a shared cache. Grouping them puts that policy in
 * one file instead of leaving it interleaved with JSON endpoints that have no
 * use for it.
 */
#[Route('/api/comics', name: 'api_comics_')]
class ComicPageController extends AbstractController
{
    use RequiresAuthenticatedUser;

    /**
     * A stored cover filename carries a uniqid suffix, so its URL changes
     * whenever the cover is regenerated. The bytes behind a given cover URL can
     * therefore never change and the browser may keep them indefinitely.
     */
    private const COVER_CACHE_SECONDS = 31_536_000;

    /**
     * The placeholder is served from the cover URL of a comic whose file is
     * missing, so that URL is not versioned and must stay revalidatable. A short
     * lifetime plus a conditional request keeps repeat views cheap without
     * pinning a "missing cover" answer in the cache for a year.
     */
    private const COVER_PLACEHOLDER_CACHE_SECONDS = 300;

    /**
     * A page URL carries no version, and nothing in the app replaces a comic's
     * archive in place, so the bytes behind one are stable in practice. A day is
     * long enough to cover a reading session and a return to it, short enough
     * that an archive swapped by hand is not served stale for a week. The ETag
     * makes the revalidation after that a cheap 304.
     */
    private const PAGE_CACHE_SECONDS = 86_400;

    public function __construct(
        private readonly string $comicsDirectory,
        private readonly LoggerInterface $logger,
        private readonly ComicAccess $comicAccess,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir
    ) {
    }

    /**
     * Everything the reader needs to lay a comic out before it downloads any of
     * it: how many pages, what sizes may be asked for, and the shape of the
     * pages that are already known.
     *
     * Geometry is a description of the comic, so it is behind exactly the same
     * check as the pages themselves — an explicit comic pending age
     * confirmation must not leak its page shapes any more than its artwork.
     * Nothing internal is exposed: no archive entry names, no filesystem paths.
     */
    #[Route('/{id}/pages', name: 'page_manifest', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function pageManifest(
        int $id,
        Request $request,
        PageDerivativeService $derivatives
    ): Response {
        $this->requireUser();

        $comic = $this->comicAccess->requireComic($id, ComicVoter::VIEW);

        $from = max(1, $request->query->getInt('from', 1));
        $manifest = $derivatives->describePages($comic, $from);

        $response = $this->json([
            'pageCount' => $comic->getPageCount() ?? 0,
            'variants' => PageVariant::widths(),
            'pages' => $manifest['pages'],
            'complete' => $manifest['complete'],
        ]);

        // A partial manifest grows as pages are read, so it is never worth a
        // browser holding on to: the next request is the point of asking again.
        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-store');
        $response->headers->set(AbstractSessionListener::NO_AUTO_CACHE_CONTROL_HEADER, 'true');

        return $response;
    }

    #[Route('/{id}/pages/{page}', name: 'get_page', methods: ['GET'])]
    public function getPage(
        int $id,
        int $page,
        Request $request,
        ComicService $comicService,
        PageDerivativeService $derivatives
    ): Response {
        $this->requireUser();

        // Pages are the comic itself, so they go through exactly the same check
        // as its metadata.
        $comic = $this->comicAccess->requireComic($id, ComicVoter::VIEW);

        // Validate page number
        if ($page < 1 || ($comic->getPageCount() !== null && $page > $comic->getPageCount())) {
            return $this->json(['message' => 'Invalid page number'], Response::HTTP_BAD_REQUEST);
        }

        // Refused rather than rounded to the nearest known size: an unknown
        // variant means the client and the server disagree about what exists,
        // and quietly serving something else hides that until somebody wonders
        // why a phone is downloading full-size scans.
        $variant = PageVariant::fromRequestValue($request->query->get('variant'));
        if ($variant === null) {
            return $this->json(
                ['message' => 'Unknown page variant.', 'variants' => array_keys(PageVariant::widths())],
                Response::HTTP_BAD_REQUEST
            );
        }

        // Always look for the comic in the user's directory first
        $owner = $comic->getOwner();
        if ($owner === null) {
            throw new ComicNotAccessibleException();
        }
        $relativePath = basename((string) $comic->getFilePath());
        $userDirectory = $this->comicsDirectory . '/' . $owner->getId();
        $filePath = $userDirectory . '/' . $relativePath;

        // Fallback to old path if file doesn't exist in user directory
        if (!file_exists($filePath)) {
            $legacyPath = $this->comicsDirectory . '/' . $relativePath;

            // If still not found, return error
            if (!file_exists($legacyPath)) {
                return $this->json(['message' => 'Comic file not found'], Response::HTTP_NOT_FOUND);
            }

            // If found in the old location, copy it into the user's directory for future access
            if (@mkdir($userDirectory, 0775, true) || is_dir($userDirectory)) {
                if (@copy($legacyPath, $filePath)) {
                    $legacyPath = $filePath;
                }
            }

            if (!is_file($filePath)) {
                $this->logger->info('Serving comic page from the legacy storage location.', ['comic_id' => $comic->getId()]);
            }

            $filePath = $legacyPath;
        }

        // Settle on one answer for "which file is this comic". The block above
        // decides whether the comic exists and migrates a legacy copy into the
        // owner's directory; ComicService is what the page cache keys itself
        // on. Re-resolving here, after any migration, keeps the validator below
        // describing the same file the cache does — otherwise a comic found by
        // two different candidate orders gets an ETag for one file and cached
        // pages for another.
        $filePath = $comicService->locateComicSource($comic) ?? $filePath;

        // Validators taken from the archive rather than the extracted page, so a
        // revalidation can be answered without opening the CBZ at all. Reading a
        // comic is the app's hot path and every page used to be re-downloaded.
        $response = new Response();
        $response->setPrivate();
        $response->setMaxAge(self::PAGE_CACHE_SECONDS);
        $response->headers->set(AbstractSessionListener::NO_AUTO_CACHE_CONTROL_HEADER, 'true');

        $modifiedAt = @filemtime($filePath);
        if ($modifiedAt !== false) {
            $response->setLastModified(new \DateTimeImmutable('@' . $modifiedAt));
            // The variant, the render version and the delivery format are all
            // part of the validator: a thumbnail and a full page live at the
            // same URL but for the query string, and a server that gains or
            // loses its WebP encoder starts producing different bytes for the
            // same page. A copy from before any of that must not be revalidated
            // as still current.
            $response->setEtag(hash(
                'sha256',
                $filePath . '|' . $modifiedAt . '|' . @filesize($filePath) . '|' . $page
                    . '|' . $derivatives->validatorSignature($variant)
            ));

            if ($response->isNotModified($request)) {
                return $response;
            }
        }

        try {
            $pageResult = $derivatives->getOrCreate($comic, $page, $variant)->page;
        } catch (\Throwable $exception) {
            $this->logger->error('Failed to read a comic source.', [
                'comic_id' => $comic->getId(),
                'exception' => $exception,
            ]);

            return $this->json(['message' => 'Failed to read comic page'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        // Return image, keeping the caching policy set up before the archive was
        // opened.
        $response->setContent($pageResult->content);
        $response->headers->set('Content-Type', $pageResult->mimeType);
        return $response;
    }

    /**
     * Download the original comic source.
     *
     * Owners only, deliberately: this is the backup path for your own library.
     * A shared comic is read through the reader, and handing a recipient the
     * archive would put a second permanent copy outside the owner's control —
     * which is exactly what the sharing model exists to avoid. Administrators
     * are not exempted either; moderating a library is not a reason to take a
     * copy of somebody's files.
     */
    #[Route('/{id}/download', name: 'download', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function download(int $id, ComicService $comicService): Response
    {
        $user = $this->requireUser();

        // Reading rights first, so a stranger is told only what a stranger is
        // told anywhere else. A recipient gets past this and is refused below
        // by name: they already know the comic exists, and "no such comic"
        // would be a worse answer than the true one.
        $comic = $this->comicAccess->requireComic($id, ComicVoter::VIEW);

        if ($comic->getOwner()?->getId() !== $user->getId()) {
            return $this->json(
                ['message' => 'Only the owner of a comic can download its file.'],
                Response::HTTP_FORBIDDEN
            );
        }

        $archivePath = $comicService->locateComicSource($comic);
        if ($archivePath === null) {
            return $this->json(['message' => 'Comic file not found'], Response::HTTP_NOT_FOUND);
        }

        $response = new BinaryFileResponse($archivePath);
        $response->setPrivate();
        // The stored filename carries a uniqid, so the download is named after
        // the comic instead. setContentDisposition escapes it and supplies an
        // ASCII fallback for titles that are not.
        $response->setContentDisposition(
            ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            $this->downloadFilename($comic)
        );
        $response->headers->set('Content-Type', $comic->getSourceType()->mimeType());
        $response->headers->set(AbstractSessionListener::NO_AUTO_CACHE_CONTROL_HEADER, 'true');

        return $response;
    }

    private function downloadFilename(Comic $comic): string
    {
        $title = trim((string) $comic->getTitle());
        // Reserved and path-significant characters are dropped rather than
        // escaped, so nothing in a title can steer where the file lands.
        $safeTitle = trim((string) preg_replace('/[\x00-\x1F\/\\\\:*?"<>|]+/u', ' ', $title));
        $safeTitle = (string) preg_replace('/\s+/u', ' ', $safeTitle);

        if ($safeTitle === '') {
            $safeTitle = 'comic-' . $comic->getId();
        }

        return mb_substr($safeTitle, 0, 100) . '.' . $comic->getSourceType()->value;
    }

    /**
     * Serve an image the browser is allowed to keep.
     *
     * Covers are private: they are reachable only through this authenticated
     * endpoint, so the policy stops at the user's own browser and never lets a
     * shared proxy hand one user's cover to another.
     *
     * Within that one browser the entry is still keyed by the session cookie,
     * so an admin who signs out and another account signs in cannot be served
     * the previous account's cover from cache. The session cookie is the only
     * credential this endpoint authenticates with, so it is the whole of Vary.
     */
    private function cacheableImageResponse(
        string $absolutePath,
        Request $request,
        int $maxAge,
        bool $immutable
    ): BinaryFileResponse {
        $response = new BinaryFileResponse($absolutePath);
        $response->setAutoLastModified();
        $response->setAutoEtag();
        $response->setPrivate();
        $response->setMaxAge($maxAge);
        $response->setVary('Cookie');
        if ($immutable) {
            $response->setImmutable();
        }

        // Symfony disables caching on every response whose request touched the
        // session, which is every authenticated request. Opting out is what
        // lets the policy above reach the browser at all.
        $response->headers->set(AbstractSessionListener::NO_AUTO_CACHE_CONTROL_HEADER, 'true');

        // Turns a revalidation into an empty 304 instead of resending the file.
        $response->isNotModified($request);

        return $response;
    }

    #[Route('/cover/{userId}/{comicId}/{filename}', name: 'cover_image', methods: ['GET'])]
    public function getCoverImage(
        int $userId,
        int $comicId,
        string $filename,
        Request $request,
        ComicCoverService $covers
    ): Response {
        $this->requireUser();

        // The owner id in the URL only has to agree with the comic; whether this
        // request may see the cover is the voter's decision, so a recipient's
        // cover request is not rejected for pointing at somebody else's id.
        $comic = $this->comicAccess->requireComic($comicId, ComicVoter::VIEW);
        if ($comic->getOwner()?->getId() !== $userId) {
            throw new ComicNotAccessibleException();
        }

        $coverPath = $comic->getCoverImagePath();
        if (!$coverPath) {
            return $this->json(['message' => 'Comic has no cover image path.'], Response::HTTP_NOT_FOUND);
        }

        $expectedFilename = basename($coverPath);
        if ($filename !== $expectedFilename) {
            $this->logger->warning('Invalid cover filename requested.', ['comic_id' => $comicId, 'user_id' => $userId]);
            return $this->json(['message' => 'Invalid filename requested.'], Response::HTTP_NOT_FOUND);
        }

        $absolutePath = $this->comicsDirectory . '/' . $userId . '/' . ltrim($coverPath, '/');

        if (!file_exists($absolutePath) || !is_readable($absolutePath)) {
            $this->logger->warning('Cover file not found or unreadable.', ['comic_id' => $comicId, 'user_id' => $userId]);
            $placeholderPath = $this->projectDir.'/public/comic.png';
            if (is_readable($placeholderPath)) {
                return $this->cacheableImageResponse(
                    $placeholderPath,
                    $request,
                    self::COVER_PLACEHOLDER_CACHE_SECONDS,
                    false
                );
            }

            return $this->json(['message' => 'Cover image file not found on server.'], Response::HTTP_NOT_FOUND);
        }

        // BinaryFileResponse handles Content-Type, Content-Length and range
        // requests; the helper adds the caching policy on top so returning to
        // the library re-displays covers from the browser cache.
        $rendition = $covers->getOrCreate($comicId, $absolutePath);
        $optimized = $rendition !== $absolutePath;

        return $this->cacheableImageResponse(
            $rendition,
            $request,
            $optimized ? self::COVER_CACHE_SECONDS : self::COVER_PLACEHOLDER_CACHE_SECONDS,
            $optimized
        );
    }
}
