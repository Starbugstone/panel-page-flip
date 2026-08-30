import { adSenseScriptSrc, consentPlatformScriptSrc, settleOnce } from "@/lib/advertising";
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

const INJECTED_AD_SELECTOR = INJECTED_AD_SELECTORS.join(",");

/**
 * How long to wait for the site code before giving up on it.
 *
 * Some blockers neither serve the script nor fail the request, so waiting on the
 * `error` event alone can wait for ever. Five seconds is long enough for a slow
 * connection and keeps the diagnostic status from remaining "loading" forever.
 */
const SCRIPT_TIMEOUT_MS = 5000;

/**
 * The outcome of every script this module has asked for, by element id.
 *
 * The request is memoised rather than the element inspected, because "has this
 * been asked for" is a question about this module's own history and the DOM is
 * a poor place to keep it: a script the timeout gave up on looks exactly like
 * one nothing ever waited for. Holding the promise means a second caller joins
 * the first attempt instead of attaching another pair of listeners and another
 * five-second timer to a node that lives for the whole session.
 */
const inFlight = new Map();

/**
 * Attach the one set of handlers that decides a script tag's outcome.
 *
 * Settles exactly once: a script that arrives at 5.5s must not resolve "ready"
 * after its promise already said "unavailable", or the caller's cached answer
 * permanently contradicts the DOM.
 */
function settleScript(script, timeoutMs, { onError } = {}) {
  const { promise, settle } = settleOnce(timeoutMs);

  script.addEventListener("load", () => settle("ready"), { once: true });
  script.addEventListener("error", () => {
    onError?.();
    settle("unavailable");
  }, { once: true });

  return promise;
}

/**
 * Load a Google script tag once, handing every later caller the first outcome.
 *
 * @returns {Promise<"ready" | "unavailable">}
 */
function loadOnce({ doc, id, src, timeoutMs, onError }) {
  const asked = inFlight.get(id);
  if (asked) return asked;

  const script = doc.createElement("script");
  script.id = id;
  script.async = true;
  script.crossOrigin = "anonymous";
  script.src = src;

  const settled = settleScript(script, timeoutMs, { onError });
  inFlight.set(id, settled);
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

  const injected = root.querySelectorAll(INJECTED_AD_SELECTOR);
  injected.forEach((element) => element.remove());

  return injected.length;
}

function isAd(node) {
  return node.nodeType === 1 && node.matches(INJECTED_AD_SELECTOR);
}

function isOrContainsAd(node) {
  if (node.nodeType !== 1) return false;

  return node.matches(INJECTED_AD_SELECTOR) || node.querySelector(INJECTED_AD_SELECTOR) !== null;
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
        // The element itself, not its subtree: an attribute change cannot put a
        // new node under it, and anything that did arrives as a childList
        // record. Searching the subtree here would run a full query for every
        // image the reader swaps.
        ? isAd(record.target)
        : [...record.addedNodes].some(isOrContainsAd)
    ));

    if (arrived) removeInjectedAds(root);
  });

  // Attributes as well as insertions, because Auto Ads also take over elements
  // that are already on the page by stamping their own markers onto them —
  // childList alone never sees that. Filtered rather than watching every
  // attribute, which in the reader would fire on every class change a page turn
  // makes.
  //
  // `src` is on the list because an iframe is matched by its URL: one inserted
  // blank and pointed at Google afterwards matches nothing at insertion, and
  // without this the mutation that makes it an advertisement is the one
  // mutation not watched. It costs a `matches()` on each image the reader
  // swaps, which is nothing beside an advertisement surviving on the page.
  observer.observe(target, {
    childList: true,
    subtree: true,
    attributes: true,
    attributeFilter: ["src", "data-google-query-id", "data-anchor-status", "data-vignette-loaded"],
  });

  return () => observer.disconnect();
}

/** Test seam: forget the loaded scripts so the next call starts over. */
export function resetAdSenseScriptForTesting(doc = typeof document === "undefined" ? null : document) {
  inFlight.clear();
  doc?.getElementById(SCRIPT_ID)?.remove();
  doc?.getElementById(CMP_SCRIPT_ID)?.remove();
}

export { SCRIPT_ID as ADSENSE_SCRIPT_ID, CMP_SCRIPT_ID };
