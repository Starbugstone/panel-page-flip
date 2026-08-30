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
export function useReaderNextComic(comic) {
  const [catalog, setCatalog] = useState(null);

  useEffect(() => {
    let active = true;

    api.get("/api/comics")
      .then((data) => { if (active) setCatalog(data.comics || []); })
      .catch((error) => logger.warn("Could not load the comic catalog for reader navigation:", error));

    return () => { active = false; };
  }, []);

  return useMemo(
    () => nextComicForReader(catalog, comic),
    [catalog, comic]
  );
}
