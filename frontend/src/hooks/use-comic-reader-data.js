import { useEffect, useState } from "react";

import { api } from "@/lib/api";
import { logger } from "@/lib/logger";

/**
 * The comic this reader is for, and the states on the way to having it.
 *
 * The page count stays with the caller rather than being returned from here:
 * the page cache and the navigation both need it before this hook can run, and
 * routing it back out would put the reader a render behind its own comic.
 * `onStart` and `onLoaded` are where everything held about a comic — cached
 * pages, the current page — is put back for the new one, inside the same effect
 * as the request so a second comic cannot show the first one's pages.
 */
export function useComicReaderData({ comicId, navigate, toast, onStart, onLoaded }) {
  const [comic, setComic] = useState(null);
  const [loadError, setLoadError] = useState(null);
  const [isFetching, setIsFetching] = useState(true);

  useEffect(() => {
    let active = true;

    const loadComic = async () => {
      setIsFetching(true);
      setLoadError(null);
      setComic(null);
      onStart();

      try {
        const data = await api.get(`/api/comics/${comicId}`);
        if (!active) return;
        const count = data.comic?.pageCount ?? 0;
        setComic(data.comic);
        onLoaded(data.comic, count);
        if (count <= 0) {
          toast({ title: "Comic has no pages", description: "This comic cannot be displayed as it has no pages.", variant: "destructive" });
        }
      } catch (error) {
        if (!active) return;
        logger.error("Failed to load comic:", error);
        setLoadError(error);
        // A 404 already gets a page of its own saying so; a toast on top of it
        // says the same thing twice.
        if (error.status !== 404) {
          toast({
            title: "Error loading comic",
            description: error.status >= 500
              ? "The server had a problem loading this comic. Please try again in a moment."
              : "There was a problem loading the comic. Please try again.",
            variant: "destructive",
          });
        }
      } finally {
        if (active) setIsFetching(false);
      }
    };

    if (comicId) void loadComic();
    else navigate("/dashboard");

    return () => { active = false; };
  }, [comicId, navigate, onLoaded, onStart, toast]);

  return { comic, loadError, isFetching };
}
