import { useCallback, useEffect, useState } from "react";

import { api } from "@/lib/api";
import { logger } from "@/lib/logger";

/**
 * This account's own storage figures.
 *
 * Two places want them — the library sidebar, where they are ambient, and the
 * settings page, where somebody has gone looking. Both read the same endpoint
 * so they cannot disagree, and both treat a failure the same way: no numbers
 * rather than wrong ones.
 *
 * Never an error banner. Storage use is background information on both
 * surfaces; a library that will not render because a sum did not come back is
 * a worse outcome than a library with no bar on it.
 */
export function useStorageUsage() {
  const [state, setState] = useState({ usage: null, isLoading: true });

  const fetchUsage = useCallback(
    () => api.get("/api/me/storage").catch((error) => {
      logger.error("Could not load storage usage:", error);

      return null;
    }),
    []
  );

  useEffect(() => {
    let ignore = false;

    fetchUsage().then((usage) => {
      if (!ignore) setState({ usage, isLoading: false });
    });

    return () => { ignore = true; };
  }, [fetchUsage]);

  /** After an upload or a deletion, when the numbers on screen have gone stale. */
  const reload = useCallback(
    () => fetchUsage().then((usage) => {
      setState({ usage, isLoading: false });

      return usage;
    }),
    [fetchUsage]
  );

  return { usage: state.usage, isLoading: state.isLoading, reload };
}
