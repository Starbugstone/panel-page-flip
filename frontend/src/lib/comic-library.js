/**
 * Pure helpers behind the comic library store.
 *
 * The store keeps comic metadata only. Cover image bytes stay with the browser
 * cache, which already handles memory, disk, revalidation and eviction; a second
 * hand-rolled image cache would need its own invalidation and size limits for no
 * gain.
 */

/**
 * How long a cached library stays usable without a background refresh. Long
 * enough that returning from a comic reuses the cards on screen, short enough
 * that a second tab's upload shows up on the next visit.
 */
export const LIBRARY_STALE_MS = 30 * 1000;

/**
 * One request produces one cached list. The URL alone is not enough: the fuzzy
 * query is applied client-side after the response, so two visits sharing a URL
 * can still hold different comics.
 */
export function libraryRequestKey(url, fuzzyQuery = "") {
  return `${url}::${fuzzyQuery}`;
}

export function isLibraryStale(fetchedAt, now = Date.now(), staleMs = LIBRARY_STALE_MS) {
  if (!fetchedAt) return true;
  return now - fetchedAt >= staleMs;
}

/**
 * Flatten the API shape into what the cards and table read. `tags` becomes a
 * list of names for filtering and display; the full objects stay on
 * `tagDetails` for anything that needs the badge metadata.
 */
export function normaliseComic(comic) {
  const tags = comic.tags || [];

  return {
    ...comic,
    tagDetails: tags,
    hiddenTagNames: tags.filter((tag) => tag.hideFromLibrary).map((tag) => tag.name),
    tags: tags.map((tag) => tag.name),
    lastReadPage: comic.readingProgress ? comic.readingProgress.currentPage : undefined,
  };
}

export function normaliseComics(comics) {
  return (comics || []).map(normaliseComic);
}

/**
 * Apply a reading position the reader just saved. Returns the same array when
 * the comic is not in this list, so a store update cannot pointlessly re-render
 * every card.
 *
 * A null progress clears the position, which is what resetting does.
 */
export function applyProgressUpdate(comics, comicId, progress) {
  const targetId = String(comicId);
  let changed = false;

  const updated = comics.map((comic) => {
    if (String(comic.id) !== targetId) return comic;
    changed = true;

    if (!progress) {
      return { ...comic, readingProgress: null, lastReadPage: undefined };
    }

    const readingProgress = { ...(comic.readingProgress || {}), ...progress };
    return { ...comic, readingProgress, lastReadPage: readingProgress.currentPage };
  });

  return changed ? updated : comics;
}

export function removeComics(comics, comicIds) {
  const removed = new Set((comicIds || []).map(String));
  if (removed.size === 0) return comics;

  const remaining = comics.filter((comic) => !removed.has(String(comic.id)));
  return remaining.length === comics.length ? comics : remaining;
}
