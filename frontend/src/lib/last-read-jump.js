/**
 * Finding the way back to the comic that was read most recently.
 *
 * The grid keeps no scroll position between visits, so a long folder means
 * hunting for the one cover with a progress bar. The jump button lands the
 * view on it instead.
 */

/**
 * The comic read most recently, judged by the server's `lastReadAt` stamp.
 * Comics never opened — or whose stamp does not parse — cannot be where
 * anyone left off, so they are ignored. Returns null when nothing qualifies.
 */
export function latestReadComic(comics) {
  let latest = null;
  let latestTime = -Infinity;

  for (const comic of comics || []) {
    const time = Date.parse(comic.readingProgress?.lastReadAt ?? "");
    if (Number.isNaN(time) || time <= latestTime) continue;
    latest = comic;
    latestTime = time;
  }

  return latest;
}

// Long enough to spot after the smooth scroll settles, short enough that the
// ring is gone before it starts looking like a selection state.
const HIGHLIGHT_MS = 2000;
const HIGHLIGHT_CLASSES = ["ring-2", "ring-comic-purple", "ring-offset-2", "rounded-lg"];

/**
 * Scroll the card for `comicId` to the middle of the view and flash a ring on
 * it, so the eye finds it among a page of near-identical covers. Returns
 * whether the card was on the page at all.
 */
export function jumpToComicCard(comicId, root = document) {
  const card = root.querySelector(`[data-comic-id="${CSS.escape(String(comicId))}"]`);
  if (!card) return false;

  card.scrollIntoView({ behavior: "smooth", block: "center" });
  // Only take back what this flash added. The wrapper is otherwise unrounded;
  // stripping every highlight class would also strip a `rounded-lg` the card
  // already had.
  const addedClasses = HIGHLIGHT_CLASSES.filter((className) => !card.classList.contains(className));
  card.classList.add(...addedClasses);
  setTimeout(() => card.classList.remove(...addedClasses), HIGHLIGHT_MS);
  return true;
}
