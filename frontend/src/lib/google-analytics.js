import { logger } from "@/lib/logger";
import { settleOnce } from "@/lib/advertising";

const SCRIPT_TIMEOUT_MS = 5000;
const ANALYTICS_COOKIE_SECONDS = 13 * 30 * 24 * 60 * 60;
const SCRIPT_ID = "google-analytics-tag";
const inFlight = new Map();
const localConsentModeStates = new WeakMap();

const DENIED_CONSENT = Object.freeze({
  ad_storage: "denied",
  ad_user_data: "denied",
  ad_personalization: "denied",
  analytics_storage: "denied",
});

/**
 * The only routes that ever produce a page view, and the fixed title each one
 * reports.
 *
 * An allowlist, so a new route is unmeasured until somebody decides otherwise.
 *
 * The legal-policy pages are deliberately absent and must stay absent: Google
 * requires the privacy-policy URL configured in Privacy & Messaging to carry no
 * consent-requiring tag, and `google-free-routes.js` enforces that for the
 * whole set. `/report-content` is absent for a different reason — it is the
 * illegal-content reporting workflow, and a page view saying somebody opened it
 * is measurement of a legally sensitive act that nothing here needs.
 */
const ANALYTICS_PAGES = Object.freeze({
  "/": "Landing page",
  "/login": "Login",
  "/forgot-password": "Forgot password",
  "/dashboard": "Library",
  "/upload": "Upload comic",
  "/upload/bulk": "Bulk upload information",
  "/upload/bulk/session": "Bulk upload session",
  "/sharing": "Sharing",
  "/dropbox-sync": "Dropbox import",
  "/settings": "Settings",
});

export function analyticsPageFor(pathname) {
  if (typeof pathname !== "string") return null;
  const [withoutQuery] = pathname.split(/[?#]/);
  const normalized = withoutQuery.length > 1 ? withoutQuery.replace(/\/+$/, "") : withoutQuery;
  const title = ANALYTICS_PAGES[normalized];

  return title ? { path: normalized, title } : null;
}

export function analyticsScriptSrc(measurementId) {
  return `https://www.googletagmanager.com/gtag/js?id=${encodeURIComponent(measurementId)}`;
}

function ensureGoogleTagQueue(win) {
  win.dataLayer = win.dataLayer || [];
  win.gtag = win.gtag || function gtag(...args) { win.dataLayer.push(args); };
}

/**
 * Apply the first-party banner's grant using Google's required Consent Mode v2
 * order. Advertising remains denied because this path exists only when AdSense
 * is off, and prevents a later GA account link from silently widening consent.
 */
export function grantLocalAnalyticsConsent(
  { win = typeof window === "undefined" ? null : window } = {}
) {
  if (!win) return false;
  ensureGoogleTagQueue(win);

  const previousState = localConsentModeStates.get(win);
  if (!previousState) {
    win.gtag("consent", "default", DENIED_CONSENT);
    win.gtag("set", "ads_data_redaction", true);
  }
  if (previousState !== "granted") {
    win.gtag("consent", "update", { ...DENIED_CONSENT, analytics_storage: "granted" });
    localConsentModeStates.set(win, "granted");
  }

  return true;
}

export function denyLocalAnalyticsConsent(
  { win = typeof window === "undefined" ? null : window } = {}
) {
  if (!win?.gtag || !localConsentModeStates.has(win)) return false;
  if (localConsentModeStates.get(win) !== "denied") {
    win.gtag("consent", "update", DENIED_CONSENT);
    localConsentModeStates.set(win, "denied");
  }

  return true;
}

function initialiseQueue(win, measurementId, pageFields) {
  ensureGoogleTagQueue(win);
  win[`ga-disable-${measurementId}`] = false;
  win.gtag("js", new Date());
  win.gtag("config", measurementId, {
    ...pageFields,
    send_page_view: false,
    allow_google_signals: false,
    allow_ad_personalization_signals: false,
    cookie_expires: ANALYTICS_COOKIE_SECONDS,
    cookie_update: false,
  });
}

export function loadGoogleAnalytics(
  measurementId,
  {
    win = typeof window === "undefined" ? null : window,
    doc = typeof document === "undefined" ? null : document,
    timeoutMs = SCRIPT_TIMEOUT_MS,
    pageFields = {},
  } = {}
) {
  if (!measurementId || !win || !doc) return Promise.resolve("unavailable");
  const asked = inFlight.get(measurementId);
  if (asked) return asked;

  initialiseQueue(win, measurementId, pageFields);

  const script = doc.createElement("script");
  script.id = SCRIPT_ID;
  script.async = true;
  script.crossOrigin = "anonymous";
  script.src = analyticsScriptSrc(measurementId);

  const { promise, settle } = settleOnce(timeoutMs);
  script.addEventListener("load", () => settle("ready"), { once: true });
  script.addEventListener("error", () => {
    logger.log("Google Analytics did not load; measurement remains unavailable.");
    settle("unavailable");
  }, { once: true });

  inFlight.set(measurementId, promise);
  (doc.head || doc.documentElement).appendChild(script);

  return promise;
}

export function setAnalyticsPageContext(
  fields,
  { win = typeof window === "undefined" ? null : window } = {}
) {
  if (!win?.gtag) return false;
  win.gtag("set", fields);

  return true;
}

export function sendAnalyticsPageView(
  measurementId,
  fields,
  { win = typeof window === "undefined" ? null : window } = {}
) {
  if (!measurementId || !win?.gtag || win[`ga-disable-${measurementId}`]) return false;

  win.gtag("event", "page_view", { ...fields, send_to: measurementId });

  return true;
}

function analyticsCookieNames(doc) {
  return doc.cookie
    .split(";")
    .map((cookie) => cookie.split("=", 1)[0].trim())
    .filter((name) => name === "_ga" || name.startsWith("_ga_"));
}

function cookieDomains(hostname) {
  if (!hostname || hostname === "localhost") return [null];
  const labels = hostname.split(".");
  const parents = labels.slice(0, -1).map((_, index) => `.${labels.slice(index).join(".")}`);

  return [null, ...parents];
}

export function removeGoogleAnalyticsCookies(doc = typeof document === "undefined" ? null : document) {
  if (!doc) return;
  const names = analyticsCookieNames(doc);

  for (const name of names) {
    for (const domain of cookieDomains(doc.location?.hostname)) {
      doc.cookie = `${name}=; Max-Age=0; Path=/; SameSite=Lax${domain ? `; Domain=${domain}` : ""}`;
    }
  }
}

export function disableGoogleAnalytics(
  measurementId,
  {
    win = typeof window === "undefined" ? null : window,
    doc = typeof document === "undefined" ? null : document,
  } = {}
) {
  if (win && measurementId) win[`ga-disable-${measurementId}`] = true;
  removeGoogleAnalyticsCookies(doc);
}

export function enableGoogleAnalytics(
  measurementId,
  { win = typeof window === "undefined" ? null : window } = {}
) {
  if (win && measurementId) win[`ga-disable-${measurementId}`] = false;
}

export function guardGoogleAnalyticsNavigation(
  measurementId,
  { win = typeof window === "undefined" ? null : window } = {}
) {
  if (!win?.history || !measurementId) return () => {};

  const disableBeforeNavigation = () => {
    win[`ga-disable-${measurementId}`] = true;
  };
  const { history } = win;
  const originalPushState = history.pushState;
  const originalReplaceState = history.replaceState;
  const guardedPushState = function guardedPushState(...args) {
    disableBeforeNavigation();
    return originalPushState.apply(this, args);
  };
  const guardedReplaceState = function guardedReplaceState(...args) {
    disableBeforeNavigation();
    return originalReplaceState.apply(this, args);
  };

  // Register before gtag loads and suspend collection before every History API
  // transition. React then explicitly re-enables it and sends only an allowlisted,
  // query-free page view. This also blocks GA's optional enhanced history listener.
  history.pushState = guardedPushState;
  history.replaceState = guardedReplaceState;
  win.addEventListener("popstate", disableBeforeNavigation);
  disableBeforeNavigation();

  return () => {
    win.removeEventListener("popstate", disableBeforeNavigation);
    if (history.pushState === guardedPushState) history.pushState = originalPushState;
    if (history.replaceState === guardedReplaceState) history.replaceState = originalReplaceState;
  };
}

export function resetGoogleAnalyticsForTesting(
  {
    win = typeof window === "undefined" ? null : window,
    doc = typeof document === "undefined" ? null : document,
  } = {}
) {
  const measurementIds = [...inFlight.keys()];
  inFlight.clear();
  doc?.getElementById(SCRIPT_ID)?.remove();
  if (win) {
    localConsentModeStates.delete(win);
    measurementIds.forEach((measurementId) => { delete win[`ga-disable-${measurementId}`]; });
    delete win.dataLayer;
    delete win.gtag;
  }
}

export { SCRIPT_ID as ANALYTICS_SCRIPT_ID };
