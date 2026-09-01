import { useEffect, useRef } from "react";

import { jumpToComicCard } from "@/lib/last-read-jump";

/**
 * Scroll to the comic the URL asked for, once it is actually on the page.
 *
 * The list arrives after the first render, and inside a folder it arrives after
 * the folder tree before it, so the jump is retried as the comics change rather
 * than attempted once on mount. The comic reached is remembered so a later
 * refresh — a saved reading position, a delete elsewhere — does not yank the
 * view back to a card the reader has already scrolled away from.
 */
export function useJumpToComic(comicId, comics) {
  const jumpedTo = useRef(null);

  useEffect(() => {
    if (comicId == null || jumpedTo.current === comicId) return;
    if (!comics.some((comic) => String(comic.id) === String(comicId))) return;
    if (jumpToComicCard(comicId)) jumpedTo.current = comicId;
  }, [comicId, comics]);
}
