import { adSenseScriptSrc, consentPlatformScriptSrc } from "@/lib/advertising";
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
const CMP_SCRIPT_ID = "google-cmp";

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
 * Attach the one set of handlers that decides a script tag's outcome.
 *
 * Every path — load, error, timeout — writes `dataset.status` and settles
 * exactly once. Both halves matter. Without the write, a script the timeout
 * gave up on is never memoised, so each later caller attaches another pair of
 * listeners and another timer to a node that lives for the whole session and
 * waits a further five seconds for an answer that already exists. Without the
 * settle-once guard, a script that arrives at 5.5s resolves "ready" after its
 * promise already said "unavailable", leaving the caller's cached answer
 * permanently contradicting the DOM.
 */
function settleScript(script, timeoutMs, { onError } = {}) {
  return new Promise((resolve) => {
    let settled = false;

    const finish = (status) => {
      if (settled) return;
      settled = true;
      clearTimeout(timer);
      script.dataset.status = status;
      resolve(status);
    };

    const timer = setTimeout(() => finish("unavailable"), timeoutMs);

    script.addEventListener("load", () => finish("ready"), { once: true });
    script.addEventListener("error", () => {
      onError?.();
      finish("unavailable");
    }, { once: true });
  });
}

/**
 * Load a Google script tag once, handing every later caller the first outcome.
 *
 * @returns {Promise<"ready" | "unavailable">}
 */
function loadOnce({ doc, id, src, timeoutMs, onError }) {
  const existing = doc.getElementById(id);
  if (existing) {
    // Already asked for. `dataset.status` is written by every settle path, so a
    // second caller gets the first attempt's outcome instead of racing it or
    // injecting a duplicate script.
    if (existing.dataset.status === "ready") return Promise.resolve("ready");
    if (existing.dataset.status === "unavailable") return Promise.resolve("unavailable");

    return settleScript(existing, timeoutMs);
  }

  const script = doc.createElement("script");
  script.id = id;
  script.async = true;
  script.crossOrigin = "anonymous";
  script.src = src;

  const settled = settleScript(script, timeoutMs, { onError });
  (doc.head || doc.documentElement).appendChild(script);

  return settled;
}

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

  return loadOnce({
    doc,
    id: SCRIPT_ID,
    src: adSenseScriptSrc(client),
    timeoutMs,
    // Not a warning. An ad blocker is a choice the reader made, and this
    // application works the same either way.
    onError: () => logger.log("AdSense site code did not load; advertising is unavailable."),
  });
}

/**
 * Load Google's consent platform on its own, without the advertising site code.
 *
 * The footer's privacy-choices control has to work on every page, and the site
 * code is deliberately loaded only on the four ad-safe routes — so on a reader
 * or library page `window.googlefc` has never existed and the control would do
 * nothing. Funding Choices is the consent half by itself: it shows the
 * message and records the answer, and serves no advertising, which is what
 * makes it safe to load on a page rendering somebody's comic.
 *
 * @returns {Promise<"ready" | "unavailable">}
 */
export function loadConsentPlatform(
  client,
  { doc = typeof document === "undefined" ? null : document, timeoutMs = SCRIPT_TIMEOUT_MS } = {}
) {
  if (!doc || !client) return Promise.resolve("unavailable");

  return loadOnce({
    doc,
    id: CMP_SCRIPT_ID,
    src: consentPlatformScriptSrc(client),
    timeoutMs,
    onError: () => logger.log("The consent platform did not load; privacy choices are unavailable."),
  });
}

/**
 * Remove advertising Google has already placed on the page.
 *
 * @returns {number} how many elements were removed, for tests and for logging
 */
export function removeInjectedAds(root = typeof document === "undefined" ? null : document) {
  if (!root) return 0;

  const injected = root.querySelectorAll(INJECTED_AD_SELECTORS.join(","));
  injected.forEach((element) => element.remove());

  return injected.length;
}

function isOrContainsAd(node) {
  if (node.nodeType !== 1) return false;

  const selector = INJECTED_AD_SELECTORS.join(",");

  return node.matches(selector) || node.querySelector(selector) !== null;
}

/**
 * Keep an ad-free route ad-free for as long as the user is on it.
 *
 * A single sweep at navigation time is not enough, and the gap it leaves is the
 * exact failure this whole feature exists to prevent. The site code cannot be
 * unloaded, so it is still resident when the reader opens a comic, and Auto Ads
 * insert on their own schedule — typically some hundreds of milliseconds after
 * the page settles. A sweep that runs once at commit finds nothing, and the
 * advertisement that arrives afterwards stays beside the artwork for the whole
 * reading session.
 *
 * So: sweep now, then watch. The observer is the guarantee; the first sweep just
 * makes it immediate.
 *
 * @returns {() => void} stop watching
 */
export function keepRouteAdFree(root = typeof document === "undefined" ? null : document) {
  if (!root) return () => {};

  removeInjectedAds(root);

  const target = root.body || root.documentElement;
  if (!target || typeof MutationObserver === "undefined") return () => {};

  // Checks what was added rather than re-querying the document on every
  // mutation. This watches for the whole time the user is on an ad-free route,
  // and the busiest of those is the reader, which mutates constantly as pages
  // turn — a full-document query per mutation would be a real cost paid on the
  // application's hottest surface to catch something that arrives once or never.
  const observer = new MutationObserver((records) => {
    const arrived = records.some((record) => (
      record.type === "attributes"
        ? isOrContainsAd(record.target)
        : [...record.addedNodes].some(isOrContainsAd)
    ));

    if (arrived) removeInjectedAds(root);
  });

  // Attributes as well as insertions, because Auto Ads also take over elements
  // that are already on the page by stamping their own markers onto them —
  // childList alone never sees that. Filtered to the three markers rather than
  // watching every attribute, which in the reader would fire on every class
  // change a page turn makes.
  observer.observe(target, {
    childList: true,
    subtree: true,
    attributes: true,
    attributeFilter: ["data-google-query-id", "data-anchor-status", "data-vignette-loaded"],
  });

  return () => observer.disconnect();
}

/** Test seam: forget the loaded scripts so the next call starts over. */
export function resetAdSenseScriptForTesting(doc = typeof document === "undefined" ? null : document) {
  doc?.getElementById(SCRIPT_ID)?.remove();
  doc?.getElementById(CMP_SCRIPT_ID)?.remove();
}

export { SCRIPT_ID as ADSENSE_SCRIPT_ID, CMP_SCRIPT_ID };
