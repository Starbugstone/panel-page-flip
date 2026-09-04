import { useEffect, useMemo, useState } from "react";

import { api } from "@/lib/api";
import { nextComicForReader } from "@/lib/library-view";
import { logger } from "@/lib/logger";

/**
 * The full visible catalog used for end-of-comic navigation.
 *
 * This deliberately does not reuse or replace the dashboard's list: that list
 * may represent one folder, a search, or an ownership filter, while ranking a
 * fallback by tags needs to see the rest of the library too.
 */
export function useReaderNextComic(comic, enabled) {
  const [catalog, setCatalog] = useState(null);

  useEffect(() => {
    if (!enabled || catalog !== null) return undefined;
    const controller = new AbortController();

    api.get("/api/comics", { signal: controller.signal })
      .then((data) => { if (!controller.signal.aborted) setCatalog(data.comics || []); })
      .catch((error) => {
        if (!controller.signal.aborted) logger.warn("Could not load the comic catalog for reader navigation:", error);
      });

    return () => controller.abort();
  }, [catalog, enabled]);

  return useMemo(
    () => nextComicForReader(catalog, comic),
    [catalog, comic]
  );
}
