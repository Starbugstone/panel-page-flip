import { useCallback, useEffect, useRef, useState } from "react";
import { useToast } from "@/hooks/use-toast.js";
import { buildLibraryUrl } from "@/lib/library-view";

const MAX_QUERY_LENGTH = 100;
const MAX_TAGS = 10;

/**
 * Loading the library, and searching across it.
 *
 * A search deliberately ignores the current folder and ownership view: it says
 * "Searching the whole library", and honouring the sidebar as well would make
 * a miss indistinguishable from a comic filed somewhere else.
 *
 * Requests are numbered because a slow one must not be allowed to turn the
 * spinner off after a later one turned it on.
 */
export function useLibrarySearch({ loadLibrary, ownership, isFolderView, activeFolderId, foldersLoading, invalidFolder }) {
  const { toast } = useToast();
  const [isSearching, setIsSearching] = useState(false);
  const [isSearchActive, setIsSearchActive] = useState(false);
  const lastComicsUrl = useRef("/api/comics");
  const lastSearchQuery = useRef("");
  const searchRequestId = useRef(0);

  const fetchComics = useCallback(async (url, fuzzyQuery = "") => {
    lastComicsUrl.current = url;
    lastSearchQuery.current = fuzzyQuery;
    const requestId = searchRequestId.current + 1;
    searchRequestId.current = requestId;
    if (fuzzyQuery || url.includes("tags=")) setIsSearching(true);
    try {
      await loadLibrary({ url, fuzzyQuery });
    } finally {
      if (searchRequestId.current === requestId) setIsSearching(false);
    }
  }, [loadLibrary]);

  const locationUrl = buildLibraryUrl({ ownership, isFolderView, activeFolderId });
  const folderLocationPending = isFolderView && (foldersLoading || invalidFolder);

  const loadComics = useCallback(async () => {
    setIsSearchActive(false);
    await fetchComics(buildLibraryUrl({ ownership, isFolderView, activeFolderId }));
  }, [activeFolderId, fetchComics, isFolderView, ownership]);

  useEffect(() => {
    // Resolve the viewer's private folder tree first. This avoids a guaranteed
    // 400/404 request for malformed or stale bookmarked folder ids before the
    // URL fallback can replace them with the root location.
    if (folderLocationPending) return undefined;
    lastComicsUrl.current = locationUrl;
    lastSearchQuery.current = "";
    const requestId = searchRequestId.current + 1;
    searchRequestId.current = requestId;
    let ignore = false;
    loadLibrary({ url: locationUrl, fuzzyQuery: "" }).finally(() => {
      if (!ignore && searchRequestId.current === requestId) setIsSearching(false);
      if (!ignore) setIsSearchActive(false);
    });
    return () => { ignore = true; };
  }, [folderLocationPending, loadLibrary, locationUrl]);

  const search = async ({ query = "", tags = [] }) => {
    const safeQuery = query.slice(0, MAX_QUERY_LENGTH);
    const safeTags = tags.slice(0, MAX_TAGS);
    if (query.length > safeQuery.length) {
      toast({
        title: "Search query truncated",
        description: `Search queries are limited to ${MAX_QUERY_LENGTH} characters.`,
        variant: "warning",
      });
    }
    if (tags.length > safeTags.length) {
      toast({
        title: "Too many tags selected",
        description: `Only the first ${MAX_TAGS} tags will be used for filtering.`,
        variant: "warning",
      });
    }
    if (!safeQuery && safeTags.length === 0) {
      await loadComics();
      return;
    }
    setIsSearchActive(true);
    const tagQuery = new URLSearchParams();
    if (safeTags.length > 0) tagQuery.set("tags", safeTags.join(","));
    await fetchComics(tagQuery.size > 0 ? `/api/comics?${tagQuery}` : "/api/comics", safeQuery);
  };

  return {
    isSearching,
    isSearchActive,
    search,
    loadComics,
    /** Reload whatever is on screen, search terms and all, after a change to it. */
    refreshCurrent: () => fetchComics(lastComicsUrl.current, lastSearchQuery.current),
  };
}
