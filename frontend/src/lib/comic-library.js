/**
 * Pure helpers behind the comic library store.
 *
 * The store keeps comic metadata only. Cover image bytes stay with the browser
 * cache, which already handles memory, disk, revalidation and eviction; a second
 * hand-rolled image cache would need its own invalidation and size limits for no
 * gain.
 */

/**
 * Identifies which list the store is currently holding. The URL alone is not
 * enough: the fuzzy query is applied client-side after the response, so two
 * visits sharing a URL can still produce different comics.
 *
 * Every visit still refetches. The key only decides whether the comics on
 * screen answer the request being made — and so whether to show a skeleton or
 * refresh quietly behind them.
 */
export function libraryRequestKey(url, fuzzyQuery = "") {
  return `${url}::${fuzzyQuery}`;
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

/**
 * Records the changes made to the library while a fetch is in flight.
 *
 * A response describes the library as it was when the server answered, so a
 * deletion or a saved reading position that landed after that is missing from
 * it. Replaying those changes over the response is what stops it resurrecting a
 * deleted comic or rewinding a page number that has already moved on.
 *
 * Only changes recorded after a load began are replayed onto it: anything older
 * was the server's to know about.
 */
export function createMutationLog() {
  let entries = [];
  let loadsInFlight = 0;

  return {
    // Returns the mark the caller passes back to rebase().
    beginLoad() {
      loadsInFlight += 1;
      return entries.length;
    },

    // The log is only worth keeping while something might still replay it.
    endLoad() {
      loadsInFlight = Math.max(0, loadsInFlight - 1);
      if (loadsInFlight === 0) entries = [];
    },

    record(mutate) {
      if (loadsInFlight > 0) entries.push(mutate);
    },

    rebase(comics, mark) {
      return entries.slice(mark).reduce((current, mutate) => mutate(current), comics);
    },

    reset() {
      entries = [];
      loadsInFlight = 0;
    },
  };
}

export function removeComics(comics, comicIds) {
  const removed = new Set((comicIds || []).map(String));
  if (removed.size === 0) return comics;

  const remaining = comics.filter((comic) => !removed.has(String(comic.id)));
  return remaining.length === comics.length ? comics : remaining;
}
