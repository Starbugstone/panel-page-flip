/**
 * Which size of a page to ask the server for.
 *
 * The ladder is short and fixed because the server's is: a variant it does not
 * recognise is refused, and one it does recognise costs a resize of a full-size
 * scan the first time anybody asks. Picking a rung is therefore a decision
 * about somebody's data plan, not a rendering detail.
 */
const READER_LADDER = [
  { name: "reader-small", width: 800 },
  { name: "reader-medium", width: 1400 },
  { name: "reader-large", width: 2200 },
];

const THUMBNAIL_VARIANT = "thumb";

/** A fallback for environments without a measurable viewport. */
const DEFAULT_READER_VARIANT = "reader-medium";

/**
 * Beyond this, extra device pixels stop being visible on comic line art and
 * start being a phone downloading four times the image it can show.
 */
const MAX_PIXEL_RATIO = 3;

const rungIndex = (variant) => READER_LADDER.findIndex((rung) => rung.name === variant);

function isPageVariantAtLeast(candidate, requested) {
  const candidateIndex = rungIndex(candidate);
  const requestedIndex = rungIndex(requested);
  return candidateIndex >= 0 && requestedIndex >= 0 && candidateIndex >= requestedIndex;
}

/**
 * The smallest bounded variant that still covers the pixels this page will
 * occupy.
 *
 * Never `original`: zoom raises the ceiling by a rung rather than reaching for
 * the source, because a 6000-pixel scan is not a zoom level, it is a download.
 */
export function selectPageVariant({ cssWidth, pixelRatio = 1, zoomLevel = 1 } = {}) {
  const width = Number.isFinite(cssWidth) && cssWidth > 0 ? cssWidth : 0;
  if (width === 0) return DEFAULT_READER_VARIANT;

  const ratio = Math.min(Math.max(Number.isFinite(pixelRatio) ? pixelRatio : 1, 1), MAX_PIXEL_RATIO);
  const zoom = Number.isFinite(zoomLevel) && zoomLevel > 1 ? zoomLevel : 1;
  const target = width * ratio * zoom;

  return (READER_LADDER.find((rung) => rung.width >= target) ?? READER_LADDER[READER_LADDER.length - 1]).name;
}

export function createPageThumbnailUrl(comicId, pageNumber) {
  if (!comicId || !Number.isInteger(pageNumber) || pageNumber < 1) return null;

  return createComicPageUrl(encodeURIComponent(String(comicId)), pageNumber, THUMBNAIL_VARIANT);
}

export function createReaderPageUrl(comicId, pageNumber, variant) {
  if (!comicId || !Number.isInteger(pageNumber) || pageNumber < 1) return null;
  return createComicPageUrl(encodeURIComponent(String(comicId)), pageNumber, variant);
}

/**
 * Whether a page cache entry is an image that can actually be drawn.
 *
 * The cache stores the two pending outcomes — `"loading"` and `"failed"` — in
 * the same slot as the image itself, so that one lookup answers "what is the
 * state of this page". Every read has to tell the sentinels apart from an
 * `Image`, which is easy to get subtly wrong: `"failed"` is truthy.
 */
export function isUsableImage(value) {
  return Boolean(value) && value !== "loading" && value !== "failed";
}

/**
 * Whether the cached page is decoded *and* sharp enough for what is being asked
 * of it.
 *
 * Both halves matter and neither is sufficient: a page decoded at the fitted
 * variant is a real image, but showing it for a zoomed-in read is showing a
 * blurred one. Taken as a pair here because the loader and the renderer both
 * ask this question — the loader from refs, mid-gesture, and the renderer from
 * state — and a reader that disagrees with its own cache about what is ready
 * either re-fetches what it has or displays what it does not.
 */
export function isPageAtVariant(cacheEntry, loadedVariant, wantedVariant) {
  return isUsableImage(cacheEntry) && isPageVariantAtLeast(loadedVariant, wantedVariant);
}

/**
 * What the reader should draw for each page of the visible unit.
 *
 * A page is drawable only when the cache contains that page's own decoded
 * image. Pending pages deliberately return no artwork: reusing the preceding
 * page makes fast navigation look as though it did not happen.
 *
 * @param {object} args
 * @param {number[]} args.unit Pages making up the reading unit on screen.
 * @param {Record<number, unknown>} args.imageCache Cache entry per page.
 * @param {Record<number, string>} args.loadedVariants Variant each page holds.
 * @param {(pageIndex: number) => string} args.variantFor Variant each page wants.
 * @param {(pageIndex: number) => void} args.onRetry
 */
export function readerPageStates({ unit, imageCache, loadedVariants, variantFor, onRetry }) {
  return unit.map((pageIndex) => {
    const exact = imageCache[pageIndex];

    return {
      pageIndex,
      image: isUsableImage(exact) ? exact : null,
      isLoading: exact !== "failed" && !isPageAtVariant(exact, loadedVariants[pageIndex], variantFor(pageIndex)),
      hasFailed: exact === "failed",
      onRetry: () => onRetry(pageIndex),
    };
  });
}

/** The unit's page states in the order this reading direction shows them. */
export function orderPageStates(states, displayOrder) {
  const byIndex = new Map(states.map((state) => [state.pageIndex, state]));
  return displayOrder.map((pageIndex) => byIndex.get(pageIndex));
}

export function createPageManifestUrl(comicId, from = 1) {
  if (!comicId) return null;

  const start = Number.isInteger(from) && from > 1 ? from : 1;
  return `/api/comics/${encodeURIComponent(String(comicId))}/pages?from=${start}`;
}

/**
 * A one-off URL for the page, so the browser has to go back to the server.
 *
 * Only the manual reload button uses this. Page URLs are deliberately stable
 * and cacheable; busting them routinely would turn every page turn into a
 * fresh download of something already on disk.
 */
export function withForcedReload(url) {
  if (!url) return url;

  return `${url}${url.includes("?") ? "&" : "?"}_force_reload=${Date.now()}`;
}

function createComicPageUrl(encodedComicId, pageNumber, variant) {
  const base = `/api/comics/${encodedComicId}/pages/${pageNumber}`;

  return variant ? `${base}?variant=${encodeURIComponent(variant)}` : base;
}
