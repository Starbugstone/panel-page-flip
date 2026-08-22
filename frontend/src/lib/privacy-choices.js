import { logger } from "@/lib/logger";

/**
 * Reopening Google's consent message.
 *
 * The whole of the consent state lives in Google's certified CMP, which the
 * AdSense site code installs. This application therefore asks the CMP to show
 * its message again and reads nothing: a copy of somebody's consent kept over
 * here could only ever be a second answer that disagrees with the real one, and
 * the wrong one to act on.
 *
 * `googlefc.callbackQueue` is Google's own way of queueing a call made before
 * the API is ready, so pushing onto it works whether or not the script has
 * finished loading.
 *
 * @returns {boolean} whether the request could be handed to the CMP at all
 */
export function reopenPrivacyChoices(win = typeof window === "undefined" ? null : window) {
  const googlefc = win?.googlefc;
  if (!googlefc) {
    // Advertising is off, blocked or still loading. Nothing to reopen, and
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
