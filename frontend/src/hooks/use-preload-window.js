import { useEffect, useMemo, useState } from "react";

import { preloadWindowFor, readNetworkHints } from "@/lib/reader-preload";

/**
 * How far around the current page to keep decoded, kept current with the
 * connection as well as the device.
 *
 * A reader on a train changes network several times in a session, and the
 * window that was right on wifi is a stalled page turn on a slow cell.
 */
export function usePreloadWindow(profile) {
  const [network, setNetwork] = useState(readNetworkHints);

  useEffect(() => {
    const connection = typeof navigator === "undefined" ? undefined : navigator.connection;
    if (!connection?.addEventListener) return undefined;

    const update = () => setNetwork(readNetworkHints());
    connection.addEventListener("change", update);
    return () => connection.removeEventListener("change", update);
  }, []);

  return useMemo(() => preloadWindowFor(profile, network), [profile, network]);
}
