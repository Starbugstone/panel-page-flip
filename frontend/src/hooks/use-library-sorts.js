import { useCallback, useState } from "react";

/**
 * The sort for whichever library view is active.
 *
 * Reading is a recency-oriented queue, while the rest of the library starts
 * alphabetically. Keeping the choices separate means visiting the tab does
 * not unexpectedly change the ordering somebody selected elsewhere.
 */
export function useLibrarySorts(activeView) {
  const [sorts, setSorts] = useState({ library: "title-asc", reading: "last-read-desc" });

  const scope = activeView === "reading" ? "reading" : "library";
  const setSort = useCallback((nextSort) => {
    setSorts((current) => ({ ...current, [scope]: nextSort }));
  }, [scope]);

  return { sort: sorts[scope], setSort };
}
