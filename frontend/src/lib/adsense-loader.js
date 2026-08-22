import { adSenseScriptSrc } from "@/lib/advertising";
import { logger } from "@/lib/logger";

/**
 * Getting Google's site code onto the page, once, and taking its output back off
 * again when the user walks into the library.
 *
 * Nothing here decides *whether* advertising should run — that is the server's
 * answer and {@link isAdSafeRoute}'s — and nothing here touches consent. The
 * site code installs Google's own certified CMP, which is what gates ad
 * requests on the consent it collects. Reimplementing any part of that here
 * would produce a second consent state that disagrees with the real one.
 */

const SCRIPT_ID = "adsense-site-code";

/**
 * What Auto Ads leave behind in the DOM.
 *
 * Kept as selectors rather than as a promise that Google will not add a
 * fifth: the sweep runs on every navigation into an ad-free route, so an
 * element this list misses is a bug to fix here, in one place.
 */
const INJECTED_AD_SELECTORS = [
  "ins.adsbygoogle",
  "[data-google-query-id]",
  "[data-anchor-status]",
  "[data-vignette-loaded]",
  "iframe[src*='googlesyndication.com']",
  "iframe[src*='doubleclick.net']",
];

/**
 * How long to wait for the site code before giving up on it.
 *
 * Some blockers neither serve the script nor fail the request, so waiting on the
 * `error` event alone can wait for ever — and something *is* waiting: the
 * bulk-upload gate holds a spinner until it knows whether a rewarded
 * advertisement can be offered. Five seconds is long enough for a slow
 * connection and short enough not to read as a broken page.
 */
export const SCRIPT_TIMEOUT_MS = 5000;

/**
 * Load the AdSense site code.
 *
 * Resolves with the resulting status rather than throwing: a blocked or failed
 * script is the ordinary case — ad blockers are common — and the application has
 * to carry on around it, not handle an exception.
 *
 * @returns {Promise<"ready" | "unavailable">}
 */
export function loadAdSenseScript(
  client,
  { doc = typeof document === "undefined" ? null : document, timeoutMs = SCRIPT_TIMEOUT_MS } = {}
) {
  if (!doc || !client) return Promise.resolve("unavailable");

  const existing = doc.getElementById(SCRIPT_ID);
  if (existing) {
    // Already asked for. `dataset.status` is set by the handlers below, so a
    // second caller gets the first attempt's outcome instead of racing it or
    // injecting a duplicate script.
    if (existing.dataset.status === "ready") return Promise.resolve("ready");
    if (existing.dataset.status === "unavailable") return Promise.resolve("unavailable");

    return new Promise((resolve) => {
      existing.addEventListener("load", () => resolve("ready"), { once: true });
      existing.addEventListener("error", () => resolve("unavailable"), { once: true });
      setTimeout(() => resolve("unavailable"), timeoutMs);
    });
  }

  return new Promise((resolve) => {
    setTimeout(() => resolve("unavailable"), timeoutMs);

    const script = doc.createElement("script");
    script.id = SCRIPT_ID;
    script.async = true;
    script.crossOrigin = "anonymous";
    script.src = adSenseScriptSrc(client);
    script.addEventListener("load", () => {
      script.dataset.status = "ready";
      resolve("ready");
    }, { once: true });
    script.addEventListener("error", () => {
      script.dataset.status = "unavailable";
      // Not a warning. An ad blocker is a choice the reader made, and this
      // application works the same either way.
      logger.log("AdSense site code did not load; advertising is unavailable.");
      resolve("unavailable");
    }, { once: true });

    (doc.head || doc.documentElement).appendChild(script);
  });
}

/**
 * Remove advertising Google has already placed on the page.
 *
 * The site code cannot be unloaded, and this is a single-page application: once
 * it has run on the landing page it is still resident when the reader opens a
 * comic. Auto Ads insert on their own schedule, so the application sweeps its
 * own DOM whenever the current route is one where advertising must not appear.
 *
 * @returns {number} how many elements were removed, for tests and for logging
 */
export function removeInjectedAds(root = typeof document === "undefined" ? null : document) {
  if (!root) return 0;

  const injected = root.querySelectorAll(INJECTED_AD_SELECTORS.join(","));
  injected.forEach((element) => element.remove());

  return injected.length;
}

/** Test seam: forget the loaded script so the next call starts over. */
export function resetAdSenseScriptForTesting(doc = typeof document === "undefined" ? null : document) {
  doc?.getElementById(SCRIPT_ID)?.remove();
}

export { SCRIPT_ID as ADSENSE_SCRIPT_ID };
