import * as React from "react";

const MOBILE_BREAKPOINT = 768;
const MOBILE_QUERY = `(max-width: ${MOBILE_BREAKPOINT - 1}px)`;

function subscribe(onChange) {
  const mql = window.matchMedia(MOBILE_QUERY);
  mql.addEventListener("change", onChange);
  return () => mql.removeEventListener("change", onChange);
}

const getSnapshot = () => window.matchMedia(MOBILE_QUERY).matches;

// Rendered on the server, or anywhere without a window, assume desktop.
const getServerSnapshot = () => false;

/**
 * The viewport is an external store, so read it with useSyncExternalStore
 * rather than mirroring it into state from an effect. Besides being what the
 * subscription is for, it means the very first render already knows the width:
 * the old version returned false until its effect ran, so anything branching on
 * it mounted the desktop layout and then swapped.
 */
export function useIsMobile() {
  return React.useSyncExternalStore(subscribe, getSnapshot, getServerSnapshot);
}
