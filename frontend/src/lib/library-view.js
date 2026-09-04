import { getComicProgressState } from "@/lib/comic-progress";

/** The views the sidebar offers. Anything else in the URL falls back to "all". */
const LIBRARY_VIEWS = new Set(["all", "mine", "shared", "reading", "unread", "dropbox"]);

const NUMERIC_ID = /^\d+$/;

/**
 * What the URL is asking for, and whether the folder it names still exists.
 *
 * A folder id can be stale — bookmarked, then deleted — or simply malformed,
 * and both must be recognised before any request is built: asking the API for
 * one is a guaranteed 400 or 404. Waiting for `foldersLoading` matters, because
 * an id is not missing merely because the tree has not arrived yet.
 *
 * `jump` names a comic to scroll to once the list arrives. Unlike the folder it
 * needs no validation beyond its shape: a comic that is not in the list is a
 * jump that does not happen, not a request that fails.
 */
export function resolveLibraryLocation(searchParams, folders, foldersLoading) {
  const folder = resolveFolder(searchParams.get("folder"), folders, foldersLoading);
  const activeView = resolveLibraryView(searchParams.get("view"));

  return {
    isFolderView: folder.requested,
    activeFolderId: folder.id,
    activeView,
    jumpComicId: numericParameter(searchParams.get("jump")),
    ownership: activeView === "mine" || activeView === "shared" ? activeView : "all",
    invalidFolder: folder.invalid,
  };
}

function resolveFolder(rawFolder, folders, loading) {
  const requested = rawFolder !== null;
  const root = rawFolder === "root";
  const numeric = typeof rawFolder === "string" && NUMERIC_ID.test(rawFolder);
  const id = numeric ? Number(rawFolder) : null;
  const missing = !loading && id !== null && !folders.some((folder) => Number(folder.id) === id);

  return { requested, id, invalid: (requested && !root && !numeric) || missing };
}

function numericParameter(value) {
  return value !== null && NUMERIC_ID.test(value) ? Number(value) : null;
}

function resolveLibraryView(value) {
  return value && LIBRARY_VIEWS.has(value) ? value : "all";
}

/**
 * The library page showing `comic`: its remembered quick view or folder, and a
 * request to scroll its card into view. This is where the reader's way out
 * points, so neither a filtered view nor a folder is lost while reading.
 *
 * Without a remembered location, an unfiled comic keeps the plain library
 * rather than being sent to the root folder view, which would hide everything
 * that *is* filed. Without a comic at all, the plain library is all there is.
 */
export function libraryPathToComic(comic, returnTo) {
  const returnMatch = typeof returnTo === "string"
    ? returnTo.match(/^\/dashboard(?:\?([^#]*))?$/)
    : null;
  const returnQuery = returnMatch ? new URLSearchParams(returnMatch[1] || "") : null;

  if (returnQuery) {
    if (comic) returnQuery.set("jump", String(comic.id));
    return returnQuery.size > 0 ? `/dashboard?${returnQuery}` : "/dashboard";
  }

  if (!comic) return "/dashboard";

  const query = new URLSearchParams();
  if (comic.libraryFolderId != null) query.set("folder", String(comic.libraryFolderId));
  query.set("jump", String(comic.id));

  return `/dashboard?${query}`;
}

/** The library request for a location, with no filter of its own. */
export function buildLibraryUrl({ ownership, isFolderView, activeFolderId }) {
  const query = new URLSearchParams();
  if (ownership !== "all") query.set("ownership", ownership);
  if (isFolderView) query.set("folder", activeFolderId == null ? "root" : String(activeFolderId));
  return query.size > 0 ? `/api/comics?${query}` : "/api/comics";
}

/**
 * The reading-state views, which the server does not filter on.
 *
 * Skipped while searching or inside a folder: both of those already decided
 * which comics are on the table, and narrowing them again by a view the user
 * is not looking at would silently hide results.
 */
export function applyViewFilter(comics, { activeView, isSearchActive, isFolderView }) {
  if (isSearchActive || isFolderView) return comics;
  if (activeView === "reading") return comics.filter((comic) => getComicProgressState(comic).label === "In progress");
  if (activeView === "unread") return comics.filter((comic) => getComicProgressState(comic).label === "Not started");
  if (activeView === "dropbox") return comics.filter((comic) => comic.tags?.includes("Dropbox"));
  return comics;
}

const byDate = (field, direction) => (a, b) => direction * (new Date(b[field] || 0) - new Date(a[field] || 0));
const byTitle = (direction) => (a, b) => direction * (a.title || "").localeCompare(b.title || "");
const lastReadAt = (comic) => Date.parse(comic.readingProgress?.lastReadAt ?? "") || 0;
const byLastRead = (a, b) => lastReadAt(b) - lastReadAt(a) || byTitle(1)(a, b);

const COMPARATORS = {
  "title-asc": byTitle(1),
  "title-desc": byTitle(-1),
  "uploaded-desc": byDate("uploadedAt", 1),
  "uploaded-asc": byDate("uploadedAt", -1),
  "updated-desc": byDate("updatedAt", 1),
  "last-read-desc": byLastRead,
};

/** Sorted copy; an unknown sort falls back to title A–Z rather than to input order. */
export function sortComics(comics, sort) {
  return [...comics].sort(COMPARATORS[sort] ?? COMPARATORS["title-asc"]);
}

const tagKey = (tag) => {
  if (tag && typeof tag === "object") {
    return `name:${String(tag.name || "").toLocaleLowerCase()}`;
  }
  return `name:${String(tag || "").toLocaleLowerCase()}`;
};

const comicTagKeys = (comic) => new Set((comic?.tags || []).map(tagKey).filter((key) => key !== "name:"));
const folderKey = (comic) => comic?.libraryFolderId == null ? "root" : String(comic.libraryFolderId);
const titleOrder = (left, right) => (left.title || "").localeCompare(right.title || "")
  || String(left.id).localeCompare(String(right.id), undefined, { numeric: true });

/**
 * Pick the next A–Z title, preferring the current comic's actual folder and
 * then the greatest tag overlap. Restricting the candidates to titles after
 * the current one keeps repeated "Next comic" actions moving forward.
 */
export function nextComicForReader(comics, currentComic) {
  if (!currentComic) return null;

  const currentTags = comicTagKeys(currentComic);
  const currentFolder = folderKey(currentComic);
  const candidates = (comics || []).filter((candidate) => (
    String(candidate.id) !== String(currentComic.id)
    && titleOrder(candidate, currentComic) > 0
  ));

  candidates.sort((left, right) => {
    const folderDifference = Number(folderKey(right) === currentFolder) - Number(folderKey(left) === currentFolder);
    if (folderDifference !== 0) return folderDifference;

    const leftOverlap = [...comicTagKeys(left)].filter((tag) => currentTags.has(tag)).length;
    const rightOverlap = [...comicTagKeys(right)].filter((tag) => currentTags.has(tag)).length;
    if (leftOverlap !== rightOverlap) return rightOverlap - leftOverlap;

    return titleOrder(left, right);
  });

  return candidates[0] ?? null;
}
