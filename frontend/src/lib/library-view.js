import { getComicProgressState } from "@/lib/comic-progress";

/** The views the sidebar offers. Anything else in the URL falls back to "all". */
export const LIBRARY_VIEWS = new Set(["all", "mine", "shared", "reading", "unread", "dropbox"]);

const FOLDER_ID_PATTERN = /^\d+$/;

/**
 * What the URL is asking for, and whether the folder it names still exists.
 *
 * A folder id can be stale — bookmarked, then deleted — or simply malformed,
 * and both must be recognised before any request is built: asking the API for
 * one is a guaranteed 400 or 404. Waiting for `foldersLoading` matters, because
 * an id is not missing merely because the tree has not arrived yet.
 */
export function resolveLibraryLocation(searchParams, folders, foldersLoading) {
  const rawFolder = searchParams.get("folder");
  const isFolderView = rawFolder !== null;
  const wellFormed = rawFolder !== null && (rawFolder === "root" || FOLDER_ID_PATTERN.test(rawFolder));
  const activeFolderId = rawFolder && rawFolder !== "root" && FOLDER_ID_PATTERN.test(rawFolder)
    ? Number(rawFolder)
    : null;

  const requestedView = searchParams.get("view") || "all";
  const activeView = LIBRARY_VIEWS.has(requestedView) ? requestedView : "all";

  const missingFolder = !foldersLoading
    && activeFolderId != null
    && !folders.some((folder) => Number(folder.id) === activeFolderId);

  return {
    isFolderView,
    activeFolderId,
    activeView,
    ownership: activeView === "mine" || activeView === "shared" ? activeView : "all",
    invalidFolder: (isFolderView && !wellFormed) || missingFolder,
  };
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

const COMPARATORS = {
  "title-asc": byTitle(1),
  "title-desc": byTitle(-1),
  "uploaded-desc": byDate("uploadedAt", 1),
  "uploaded-asc": byDate("uploadedAt", -1),
  "updated-desc": byDate("updatedAt", 1),
};

/** Sorted copy; an unknown sort falls back to title A–Z rather than to input order. */
export function sortComics(comics, sort) {
  return [...comics].sort(COMPARATORS[sort] ?? COMPARATORS["title-asc"]);
}
