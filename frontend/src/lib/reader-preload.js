/**
 * How many pages either side of this one are worth holding decoded.
 *
 * A fixed number cannot be right for both a desktop with a browser window's
 * worth of memory and a phone that will have its decoded images reclaimed under
 * it. The numbers below are starting measurements rather than constants, but
 * they live in one place, so tuning them is one edit and not a hunt through the
 * reader.
 */

const BY_DEVICE = Object.freeze({
  phone: { backward: 1, forward: 2 },
  tablet: { backward: 2, forward: 3 },
  desktop: { backward: 2, forward: 5 },
});

// Enough to keep the page that was just turned from and the one about to be
// turned to. Anything less makes going back a download.
const MINIMUM = Object.freeze({ backward: 0, forward: 1 });

/**
 * @param {object} profile from classifyViewport()
 * @param {object} network {saveData, effectiveType} as navigator.connection reports them
 */
export function preloadWindowFor(profile = {}, network = {}) {
  // Data saving is a request, not a hint: honour it before anything else and
  // fetch only the page that is about to be needed.
  if (network.saveData === true || network.effectiveType === "slow-2g" || network.effectiveType === "2g") {
    return { ...MINIMUM };
  }

  const base = BY_DEVICE[profile.device] ?? BY_DEVICE.phone;
  if (profile.memory !== "low") return { ...base };

  return {
    backward: Math.max(MINIMUM.backward, Math.floor(base.backward / 2)),
    forward: Math.max(MINIMUM.forward, Math.floor(base.forward / 2)),
  };
}

/** Read the optional Network Information API without depending on it existing. */
export function readNetworkHints(nav = typeof navigator === "undefined" ? undefined : navigator) {
  const connection = nav?.connection;
  return {
    saveData: connection?.saveData === true,
    effectiveType: typeof connection?.effectiveType === "string" ? connection.effectiveType : undefined,
  };
}
