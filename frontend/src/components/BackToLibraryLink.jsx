import { Link, useLocation } from "react-router-dom";

import { useComicLibrary } from "@/hooks/use-comic-library.jsx";
import { libraryPathToComic } from "@/lib/library-view";

const READER_PATH = /^\/read\/(\d+)(?:\/|$)/;

/**
 * The reader's way out, aimed at the comic just read rather than at the top of
 * the library: its folder, and its card scrolled into view.
 *
 * Which folder that is comes from the library the header is already holding,
 * not from a request of its own. A reader opened without one behind it — a
 * bookmark, a fresh tab, a shared link — falls back to the plain library.
 */
export function BackToLibraryLink({ className }) {
  const { pathname } = useLocation();
  const { comics } = useComicLibrary();

  const comicId = pathname.match(READER_PATH)?.[1];
  const comic = comics.find((candidate) => String(candidate.id) === comicId);

  return <Link to={libraryPathToComic(comic)} className={className}>Back to Library</Link>;
}
