import { loadConsentPlatform } from "@/lib/adsense-loader";
import { logger } from "@/lib/logger";

/**
 * Reopening Google's consent message.
 *
 * The whole of the consent state lives in Google's certified CMP, which this
 * application reads nothing out of: a copy of somebody's consent kept over here
 * could only ever be a second answer that disagrees with the real one, and the
 * wrong one to act on.
 *
 * What this does have to solve is *where*. The advertising site code loads only
 * on the four ad-safe routes, so on a reader, library or settings page
 * `window.googlefc` has never existed — and those are exactly the pages
 * somebody is on when they decide to withdraw consent. Consent that can be
 * given and not withdrawn is not consent, so the platform is fetched on demand
 * here. Funding Choices on its own is the consent half without the advertising
 * half, which is what makes it safe on a page rendering a comic.
 *
 * `googlefc.callbackQueue` is Google's own way of queueing a call made before
 * the API is ready, so pushing onto it works whether the script has finished
 * initialising or merely finished downloading.
 *
 * @returns {Promise<boolean>} whether the request reached the CMP at all
 */
export async function reopenPrivacyChoices({
  client,
  win = typeof window === "undefined" ? null : window,
  doc = typeof document === "undefined" ? null : document,
} = {}) {
  if (!win) return false;

  if (!win.googlefc) {
    await loadConsentPlatform(client, { doc });
  }

  const googlefc = win.googlefc;
  if (!googlefc) {
    // Blocked, or this installation has no publisher id. Nothing to reopen, and
    // nothing worth interrupting the user about.
    logger.log("No consent management platform is loaded; privacy choices cannot be reopened.");

    return false;
  }

  try {
    if (typeof googlefc.showRevocationMessage === "function") {
      googlefc.showRevocationMessage();

      return true;
    }

    googlefc.callbackQueue = googlefc.callbackQueue || [];
    googlefc.callbackQueue.push({
      CONSENT_API_READY: () => win.googlefc.showRevocationMessage(),
    });

    return true;
  } catch (error) {
    logger.warn("The consent management platform refused to reopen its message:", error.message);

    return false;
  }
}
