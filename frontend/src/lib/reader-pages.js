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

export const THUMBNAIL_VARIANT = "thumb";

/** What the reader asks for before it has measured anything. */
export const DEFAULT_READER_VARIANT = "reader-medium";

/**
 * Beyond this, extra device pixels stop being visible on comic line art and
 * start being a phone downloading four times the image it can show.
 */
const MAX_PIXEL_RATIO = 3;

const rungIndex = (variant) => READER_LADDER.findIndex((rung) => rung.name === variant);

export function isPageVariantAtLeast(candidate, requested) {
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

/**
 * The larger of two variants.
 *
 * The reader only ever moves up. Going back down would spend a fresh download
 * to show less than what is already on screen — the bytes are already paid for.
 */
export function largerPageVariant(current, next) {
  return rungIndex(next) > rungIndex(current) ? next : current;
}

export function createComicPageUrls(comicId, pageCount, variant) {
  if (!comicId || !Number.isInteger(pageCount) || pageCount <= 0) return [];

  const encodedComicId = encodeURIComponent(String(comicId));
  return Array.from(
    { length: pageCount },
    (_, index) => createComicPageUrl(encodedComicId, index + 1, variant)
  );
}

export function createPageThumbnailUrl(comicId, pageNumber) {
  if (!comicId || !Number.isInteger(pageNumber) || pageNumber < 1) return null;

  return createComicPageUrl(encodeURIComponent(String(comicId)), pageNumber, THUMBNAIL_VARIANT);
}

export function createReaderPageUrl(comicId, pageNumber, variant) {
  if (!comicId || !Number.isInteger(pageNumber) || pageNumber < 1) return null;
  return createComicPageUrl(encodeURIComponent(String(comicId)), pageNumber, variant);
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
