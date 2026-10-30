import { acquireConsentPlatform } from "@/lib/adsense-loader";
import { logger } from "@/lib/logger";
import { PRIVACY_CHOICES_OPENING_EVENT } from "@/lib/google-consent";

/**
 * Reopen the consent message through Google's certified CMP.
 *
 * Funding Choices drains `googlefc.callbackQueue` once during script
 * initialisation and removes the property afterwards — a click on a private
 * browsing tab leaves `googlefc.callbackQueue === null`. Pushing onto a fresh
 * array we create here, or onto a queue the library has already drained,
 * defers the call forever, which is exactly what was happening before this
 * change: the click ran, the queue grew by one arrow function, and the
 * consent panel never opened. Invoke the method directly when it exists; the
 * dot-call keeps `this` bound to `googlefc`, which is what the library
 * expects.
 *
 * The queue mechanism is still needed when the script is mid-init and
 * `showRevocationMessage` is not yet defined — the `CONSENT_API_READY`
 * fallback below covers that race. If the library has already drained by the
 * time we land here, the fallback is a no-op and the panel will not open
 * until the user reloads; that matches Funding Choices' own behaviour for
 * late callers.
 */
function reopenConsentMessage(googlefc) {
  if (typeof googlefc.showRevocationMessage === "function") {
    googlefc.showRevocationMessage();

    return;
  }

  // Library has not exposed `showRevocationMessage` yet. The callbackQueue
  // may already be gone — recreate it before pushing so the CONSENT_API_READY
  // handler has somewhere to live in case Funding Choices drains after this
  // point.
  googlefc.callbackQueue = googlefc.callbackQueue || [];
  googlefc.callbackQueue.push({
    CONSENT_API_READY: () => reopenConsentMessage(googlefc),
  });
}

/**
 * Reopening Google's consent message.
 *
 * The whole consent state lives in Google's certified CMP. The application may
 * observe its analytics-purpose result, but never stores or synthesises a copy:
 * a second consent answer kept here could disagree with the real one.
 *
 * What this does have to solve is *where*. The advertising site code loads only
 * on the four ad-safe routes, so on a reader, library or settings page
 * `window.googlefc` has never existed — and those are exactly the pages
 * somebody is on when they decide to withdraw consent. Consent that can be
 * given and not withdrawn is not consent, so the platform is fetched on demand
 * here. Funding Choices on its own is the consent half without the advertising
 * half, which is what makes it safe on a page rendering a comic.
 *
 * @returns {Promise<boolean>} whether the request reached the CMP at all
 */
export async function reopenPrivacyChoices({
  client,
  win = typeof window === "undefined" ? null : window,
  doc = typeof document === "undefined" ? null : document,
} = {}) {
  if (!win) return false;
  win.dispatchEvent?.(new Event(PRIVACY_CHOICES_OPENING_EVENT));

  await acquireConsentPlatform(client, { win, doc });

  const googlefc = win.googlefc;
  if (!googlefc) {
    // Blocked, or this installation has no publisher id. Nothing to reopen, and
    // nothing worth interrupting the user about.
    logger.log("No consent management platform is loaded; privacy choices cannot be reopened.");

    return false;
  }

  try {
    reopenConsentMessage(googlefc);

    return true;
  } catch (error) {
    logger.warn("The consent management platform refused to reopen its message:", error.message);

    return false;
  }
}
