import { Link, useLocation } from "react-router-dom";

import { useComicLibrary } from "@/hooks/use-comic-library.jsx";
import { libraryPathToComic } from "@/lib/library-view";

const READER_PATH = /^\/read\/(\d+)(?:\/|$)/;

/**
 * The reader's way out, aimed at the comic just read rather than at the top of
 * the library: the quick view or folder it was opened from, and its card
 * scrolled into view.
 *
 * A remembered library location wins. Without one — a bookmark, a fresh tab,
 * a shared link — the comic's folder comes from the library the header already
 * holds, not from a request of its own. Without either, the plain library wins.
 */
export function BackToLibraryLink({ className }) {
  const { pathname, state } = useLocation();
  const { comics } = useComicLibrary();

  const comicId = pathname.match(READER_PATH)?.[1];
  const comic = comics.find((candidate) => String(candidate.id) === comicId);

  return <Link to={libraryPathToComic(comic, state?.libraryReturnTo)} className={className}>Back to Library</Link>;
}
