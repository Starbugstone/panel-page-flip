import { useEffect, useState } from "react";

import { api } from "@/lib/api";
import { logger } from "@/lib/logger";

/**
 * Whether this installation shows advertising, as the server decided it.
 *
 * The frontend never parses environment variables of its own. There is one
 * answer, it is computed in `AdvertisingConfiguration`, and everything on this
 * side consumes it — otherwise a self-hosted operator who turns advertising off
 * would have two switches to find and one of them would be in a build.
 *
 * A request that never comes back, or comes back broken, means advertising is
 * off. That is not a fallback so much as the point: nothing in this application
 * needs advertising to work.
 *
 * Read it through {@link useAdSense}; this hook is called once, by
 * {@link AdSenseProvider}, so the whole application shares one answer and one
 * request.
 */

export const ADVERTISING_OFF = Object.freeze({ enabled: false, client: null });

export function useAdvertisingConfig() {
  const [state, setState] = useState({ config: ADVERTISING_OFF, isLoading: true });

  useEffect(() => {
    let ignore = false;

    // `notifyUnauthorized: false` because this runs on the landing page, where
    // nobody is signed in and a session prompt would be nonsense.
    api.get("/api/public-config", { notifyUnauthorized: false })
      .then((data) => {
        if (!ignore) setState({ config: data?.adsense ?? ADVERTISING_OFF, isLoading: false });
      })
      .catch((error) => {
        logger.warn("Could not load the advertising configuration:", error.message);
        if (!ignore) setState({ config: ADVERTISING_OFF, isLoading: false });
      });

    return () => { ignore = true; };
  }, []);

  return state;
}
