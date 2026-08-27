/**
 * Where advertising is allowed to run, and whether it is running at all.
 *
 * The route list is an allowlist and everything absent from it is ad-free. That
 * direction matters more than its contents: this application holds comic files
 * whose contents nobody has vetted, and Google holds the publisher responsible
 * for the page an advertisement appears on. A denylist would put every new route
 * one forgotten edit away from showing an advertisement beside somebody's
 * uploaded artwork; an allowlist puts it one deliberate edit away from being
 * allowed to.
 *
 * Google-side page exclusions are configured on top of this. They are a second
 * safeguard, not this one's replacement — see docs/advertising.md.
 */

/**
 * Application-owned pages with no user comic content on them.
 *
 * `/upload` earns its place only while it stays a plain uploader: a file
 * picker, the size and format limits, and the selected filename. The moment it
 * grows a cover preview, a page thumbnail or anything read out of the archive,
 * it comes off this list.
 *
 * `/upload/bulk` is the rewarded-access gate, which is explanatory text and two
 * buttons. `/upload/bulk/session` — the queue itself — is deliberately absent:
 * it shows filenames, progress and failures from real files.
 */
export const AD_SAFE_ROUTES = Object.freeze(["/", "/login", "/upload", "/upload/bulk"]);

/** Google's AdSense site code, which also installs the Google CMP. */
export const ADSENSE_SCRIPT_HOST = "https://pagead2.googlesyndication.com";

/** Google's consent platform on its own, with no advertising attached. */
export const CONSENT_PLATFORM_HOST = "https://fundingchoicesmessages.google.com";

export function adSenseScriptSrc(client) {
  return `${ADSENSE_SCRIPT_HOST}/pagead/js/adsbygoogle.js?client=${encodeURIComponent(client)}`;
}

/**
 * The publisher id as everything outside the site code spells it: `pub-…`
 * rather than `ca-pub-…`. Both the ads.txt record and the consent platform's
 * URL want this form.
 */
export function publisherId(client) {
  return typeof client === "string" ? client.replace(/^ca-/, "") : "";
}

export function consentPlatformScriptSrc(client) {
  return `${CONSENT_PLATFORM_HOST}/i/${encodeURIComponent(publisherId(client))}?ers=1`;
}

/**
 * Exact matches only. A prefix test would read `/upload/bulk/session` as part of
 * `/upload/bulk` and put advertising on the batch screen, which is the one page
 * in this feature that must never carry it.
 */
export function isAdSafeRoute(pathname) {
  if (typeof pathname !== "string") return false;
  const [withoutQuery] = pathname.split(/[?#]/);
  const normalised = withoutQuery.length > 1 ? withoutQuery.replace(/\/+$/, "") : withoutQuery;

  return AD_SAFE_ROUTES.includes(normalised);
}

/**
 * Whether the server said advertising is on for this installation.
 *
 * The client id is checked as well as the flag because the frontend is handed
 * whatever the endpoint returned, including nothing at all when the request
 * failed. Both halves present is the only shape that means "on".
 */
export function isAdvertisingActive(config) {
  return Boolean(config?.enabled && config?.client);
}

/**
 * Whether entering bulk upload should present the rewarded-advertisement
 * choice, or open the uploader.
 *
 * Every uncertainty resolves to "open". A blocked script, a CMP that never
 * answered, no rewarded inventory, a failed configuration request — none of
 * them are reasons to keep somebody from uploading their own files, and an
 * offer nobody can accept is a dead end rather than a choice.
 */
export function shouldOfferRewardedGate({ gateRequired, scriptStatus }) {
  return gateRequired === true && scriptStatus === "ready";
}
