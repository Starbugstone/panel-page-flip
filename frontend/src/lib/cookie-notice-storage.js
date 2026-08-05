const STORAGE_KEY = "panel-page-flip:cookie-notice-dismissed";

export function wasCookieNoticeDismissed(storage) {
  try {
    return (storage ?? globalThis.localStorage).getItem(STORAGE_KEY) === "true";
  } catch {
    return false;
  }
}

export function persistCookieNoticeDismissal(storage) {
  try {
    (storage ?? globalThis.localStorage).setItem(STORAGE_KEY, "true");
  } catch {
    // Storage can be blocked by browser privacy settings.
  }
}
